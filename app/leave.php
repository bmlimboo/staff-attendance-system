<?php
declare(strict_types=1);

/* ---------------------------------------------------------------
 * Leave, tours, training and late-arrival permission.
 *
 * Leave is stored as periods in the `leaves` table, NOT written into the
 * daily attendance rows. Every report works out "on leave" from these
 * periods when it is shown, so adding or editing leave later (even weeks
 * afterwards) immediately changes what people see.
 * ------------------------------------------------------------- */

// key => [label for the administrator, label shown to heads and deans, kind]
const LEAVE_TYPES = [
    'casual'          => ['Casual leave',               'On leave',              'leave'],
    'annual'          => ['Annual leave',               'On leave',              'leave'],
    'medical'         => ['Medical leave',              'On leave',              'leave'],
    'maternity'       => ['Maternity leave',            'On leave',              'leave'],
    'paternity'       => ['Paternity leave',            'On leave',              'leave'],
    'medical_escort'  => ['Medical escort leave',       'On leave',              'leave'],
    'eol'             => ['Extraordinary leave (EOL)',  'On leave',              'leave'],
    'tour'            => ['Official tour / travel',     'On official duty',      'duty'],
    'training'        => ['Training / workshop',        'On official duty',      'duty'],
    'block_day'       => ['Block day',                  'On official duty',      'duty'],
    'block_week'      => ['Block week',                 'On official duty',      'duty'],
    'late_permission' => ['Late arrival permission',    'Permitted late arrival', 'permission'],
];

const LEAVE_PARTS = [
    'full'        => 'Full day',
    'first_half'  => 'First half (morning off, comes after lunch)',
    'second_half' => 'Second half (afternoon off)',
];

function leave_type_label(string $t): string
{
    return LEAVE_TYPES[$t][0] ?? $t;
}

/** What heads, deans and reports see: the reason for leave stays private. */
function leave_public_label(string $t, string $part = 'full'): string
{
    $l = LEAVE_TYPES[$t][1] ?? 'Away';
    return $part === 'full' ? $l : $l . ' (half day)';
}

function leave_kind(string $t): string
{
    return LEAVE_TYPES[$t][2] ?? 'leave';
}

function fmt_days(float $d): string
{
    $s = number_format($d, 1, '.', '');
    return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
}

/* ---------------- looking leave up ---------------- */

function leave_rank(array $l): int
{
    if ($l['kind'] === 'permission') {
        return 1;
    }
    return $l['part'] === 'full' ? 3 : 2;
}

/**
 * Effective leave for each staff member and day.
 * @param int[] $userIds
 * @return array<int,array<string,array>>  [user_id][Y-m-d] => ['id','type','part','until','kind']
 */
function leaves_map(array $userIds, string $from, string $to): array
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $rows = q("SELECT id, user_id, leave_type, start_date, end_date, part, until_time FROM leaves
               WHERE user_id IN ($ph) AND start_date <= ? AND end_date >= ?",
        array_merge($userIds, [$to, $from]))->fetchAll();

    $best = [];
    foreach ($rows as $r) {
        $s = max($r['start_date'], $from);
        $e = min($r['end_date'], $to);
        $cand = [
            'id'    => (int)$r['id'],
            'type'  => $r['leave_type'],
            'part'  => $r['part'],
            'until' => $r['until_time'] ? substr($r['until_time'], 0, 5) : null,
            'kind'  => leave_kind($r['leave_type']),
        ];
        $uid = (int)$r['user_id'];
        for ($t = strtotime($s), $n = 0; $t <= strtotime($e) && $n < 800; $t = strtotime('+1 day', $t), $n++) {
            $d = date('Y-m-d', $t);
            $cur = $best[$uid][$d] ?? null;
            if (!$cur) {
                $best[$uid][$d] = $cand;
                continue;
            }
            // a morning half-day plus an afternoon half-day is a full day off
            if ($cur['kind'] !== 'permission' && $cand['kind'] !== 'permission'
                && $cur['part'] !== 'full' && $cand['part'] !== 'full' && $cur['part'] !== $cand['part']) {
                $best[$uid][$d]['part'] = 'full';
            } elseif (leave_rank($cand) > leave_rank($cur)) {
                $best[$uid][$d] = $cand;
            }
        }
    }
    return $best;
}

function leave_for(int $userId, string $date): ?array
{
    return leaves_map([$userId], $date, $date)[$userId][$date] ?? null;
}

/** Same as leaves_map() but ignores late-arrival permissions (a permission is not an absence). */
function away_for(?array $leave): ?array
{
    return ($leave && $leave['kind'] !== 'permission') ? $leave : null;
}

/* ---------------- lateness ---------------- */

/** Minute of the day after which this person is counted late. */
function arrival_base(string $staffType, ?array $leave): int
{
    $base = tmin(schedule_for($staffType)[0]);
    if ($leave) {
        if ($leave['kind'] === 'permission' && !empty($leave['until'])) {
            $base = max($base, tmin($leave['until']));
        } elseif ($leave['kind'] !== 'permission' && $leave['part'] === 'first_half') {
            $base = max($base, tmin(setting('lunch_end', '13:00')));
        }
    }
    return $base;
}

function late_minutes_with(string $checkIn, string $staffType, ?array $leave): int
{
    $diff = tmin($checkIn) - arrival_base($staffType, $leave);
    return $diff > (int)setting('grace_minutes', '0') ? $diff : 0;
}

/**
 * Re-work the late / on-time status of automatic check-ins in a date range.
 * Called whenever leave is added, changed or removed, so a permission recorded
 * a week later still clears the "late" mark. Rows the administrator edited by
 * hand are left alone.
 */
function recompute_late(int $userId, string $from, string $to): int
{
    $u = q('SELECT staff_type FROM users WHERE id = ?', [$userId])->fetch();
    if (!$u) {
        return 0;
    }
    $rows = q("SELECT id, att_date, check_in, status, late_minutes FROM attendance
               WHERE user_id = ? AND att_date BETWEEN ? AND ? AND source = 'auto' AND check_in IS NOT NULL",
        [$userId, $from, $to])->fetchAll();
    if (!$rows) {
        return 0;
    }
    $map = leaves_map([$userId], $from, $to)[$userId] ?? [];
    $changed = 0;
    foreach ($rows as $r) {
        $late = late_minutes_with($r['check_in'], $u['staff_type'], $map[$r['att_date']] ?? null);
        $status = $late > 0 ? 'late' : 'present';
        if ($status !== $r['status'] || $late !== (int)$r['late_minutes']) {
            q('UPDATE attendance SET status = ?, late_minutes = ? WHERE id = ?', [$status, $late, $r['id']]);
            $changed++;
        }
    }
    return $changed;
}

/* ---------------- counting days ---------------- */

/** Working days covered by a leave row (0.5 for a half day). */
function leave_days_count(array $row): float
{
    if (leave_kind($row['leave_type']) === 'permission') {
        return 0.0;
    }
    if ($row['part'] !== 'full') {
        return 0.5;
    }
    $hol = holidays_between($row['start_date'], $row['end_date']);
    $wd  = working_day_numbers();
    $n = 0;
    for ($t = strtotime($row['start_date']), $i = 0; $t <= strtotime($row['end_date']) && $i < 800; $t = strtotime('+1 day', $t), $i++) {
        if (in_array((int)date('N', $t), $wd, true) && !isset($hol[date('Y-m-d', $t)])) {
            $n++;
        }
    }
    return (float)$n;
}

/* ---------------- reading input (form, CSV, API) ---------------- */

function leave_norm_key(string $s): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
}

function leave_normalize_type(string $t): ?string
{
    $k = leave_norm_key($t);
    if ($k === '') {
        return null;
    }
    if (isset(LEAVE_TYPES[$k])) {
        return $k;
    }
    foreach (LEAVE_TYPES as $key => $v) {
        if (leave_norm_key($v[0]) === $k) {
            return $key;
        }
    }
    static $syn = [
        'casual_leave' => 'casual', 'cl' => 'casual', 'annual_leave' => 'annual', 'al' => 'annual',
        'medical_leave' => 'medical', 'sick' => 'medical', 'sick_leave' => 'medical',
        'maternity_leave' => 'maternity', 'paternity_leave' => 'paternity',
        'medical_escort_leave' => 'medical_escort', 'escort' => 'medical_escort',
        'extraordinary_leave' => 'eol', 'extraordinary_leave_eol' => 'eol',
        'travel' => 'tour', 'official_tour' => 'tour', 'official_travel' => 'tour', 'official_tour_travel' => 'tour',
        'workshop' => 'training', 'training_workshop' => 'training', 'seminar' => 'training', 'conference' => 'training',
        'block' => 'block_day', 'blockday' => 'block_day', 'blockweek' => 'block_week',
        'late' => 'late_permission', 'late_arrival' => 'late_permission', 'late_arrival_permission' => 'late_permission',
    ];
    return $syn[$k] ?? null;
}

function leave_normalize_part(string $p): ?string
{
    $k = leave_norm_key($p);
    if (in_array($k, ['', 'full', 'full_day', 'whole', 'whole_day', 'day'], true)) {
        return 'full';
    }
    if (in_array($k, ['first_half', 'first', 'morning', 'am', 'fh', 'half_morning'], true)) {
        return 'first_half';
    }
    if (in_array($k, ['second_half', 'second', 'afternoon', 'pm', 'sh', 'half_afternoon'], true)) {
        return 'second_half';
    }
    return null;
}

function parse_flex_date(string $s): ?string
{
    $s = trim($s);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Ymd'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d && $d->format($fmt) === $s) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

function leave_find_user(string $email, string $staffId): ?array
{
    $email = mb_strtolower(trim($email));
    $staffId = trim($staffId);
    $user = null;
    if ($email !== '' && strpos($email, '@') !== false) {
        $user = q('SELECT id, name, staff_type FROM users WHERE email = ? LIMIT 1', [$email])->fetch() ?: null;
    }
    if (!$user && $staffId !== '') {
        $user = q('SELECT id, name, staff_type FROM users WHERE staff_id = ? LIMIT 1', [$staffId])->fetch() ?: null;
    }
    if (!$user && $email !== '' && strpos($email, '@') === false) { // CSV first column may hold a staff ID
        $user = q('SELECT id, name, staff_type FROM users WHERE staff_id = ? LIMIT 1', [$email])->fetch() ?: null;
    }
    return $user;
}

/**
 * Validate and save one leave entry. $in keys: email | staff_id, type, from, to,
 * part, until, reason, ref.  Returns ['ok', 'action' (created|updated|unchanged), 'id', 'error', 'user'].
 * With $dry = true nothing is written, the result says what would happen.
 */
function leave_upsert(array $in, string $source, ?int $by = null, bool $dry = false): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'action' => 'failed', 'user' => null, 'id' => null];

    $user = null;
    if (!empty($in['user_id'])) {
        $user = q('SELECT id, name, staff_type FROM users WHERE id = ?', [(int)$in['user_id']])->fetch() ?: null;
    } else {
        $user = leave_find_user((string)($in['email'] ?? ''), (string)($in['staff_id'] ?? ''));
    }
    if (!$user) {
        $who = trim((string)($in['email'] ?? '')) ?: trim((string)($in['staff_id'] ?? ''));
        return $fail('No staff member found for "' . mb_substr($who, 0, 80) . '".');
    }
    $type = leave_normalize_type((string)($in['type'] ?? ''));
    if (!$type) {
        return $fail('Unknown leave type "' . mb_substr((string)($in['type'] ?? ''), 0, 40) . '".');
    }
    $from = parse_flex_date((string)($in['from'] ?? ''));
    $to   = parse_flex_date((string)($in['to'] ?? '')) ?? $from;
    if (!$from || !$to) {
        return $fail('Enter valid dates (YYYY-MM-DD or DD/MM/YYYY).');
    }
    if ($to < $from) {
        return $fail('The end date is before the start date.');
    }
    if ((strtotime($to) - strtotime($from)) > 400 * 86400) {
        return $fail('A single entry can cover at most 400 days.');
    }
    $part = leave_normalize_part((string)($in['part'] ?? ''));
    if ($part === null) {
        return $fail('Unknown day part "' . mb_substr((string)($in['part'] ?? ''), 0, 30) . '" (use full, first_half or second_half).');
    }
    $until = null;
    if (leave_kind($type) === 'permission') {
        $part = 'full';
        $u = trim((string)($in['until'] ?? ''));
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $u)) {
            return $fail('Late-arrival permission needs the allowed arrival time, e.g. 10:00.');
        }
        $until = str_pad($u, 5, '0', STR_PAD_LEFT) . ':00';
    } elseif ($part !== 'full' && $from !== $to) {
        return $fail('Half-day leave must be a single day.');
    }
    $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 255);
    $ref    = mb_substr(trim((string)($in['ref'] ?? '')), 0, 60);

    $existing = null;
    $byId = !empty($in['id']);
    if ($byId) {   // editing a known record from the Leave register
        $existing = q('SELECT * FROM leaves WHERE id = ?', [(int)$in['id']])->fetch() ?: null;
        if (!$existing) {
            return $fail('That record no longer exists.');
        }
    } elseif ($ref !== '') {
        $existing = q('SELECT * FROM leaves WHERE source = ? AND external_ref = ?', [$source, $ref])->fetch() ?: null;
    }
    if ($existing) {
        $same = (int)$existing['user_id'] === (int)$user['id'] && $existing['leave_type'] === $type
            && $existing['start_date'] === $from && $existing['end_date'] === $to && $existing['part'] === $part
            && (string)$existing['until_time'] === (string)$until && $existing['reason'] === $reason;
        if ($same) {
            return ['ok' => true, 'action' => 'unchanged', 'id' => (int)$existing['id'], 'error' => null, 'user' => $user['name']];
        }
        if (!$dry) {
            q('UPDATE leaves SET user_id=?, leave_type=?, start_date=?, end_date=?, part=?, until_time=?, reason=? WHERE id=?',
                [$user['id'], $type, $from, $to, $part, $until, $reason, $existing['id']]);
            recompute_late((int)$existing['user_id'], $existing['start_date'], $existing['end_date']);
            recompute_late((int)$user['id'], $from, $to);
        }
        return ['ok' => true, 'action' => 'updated', 'id' => (int)$existing['id'], 'error' => null, 'user' => $user['name']];
    }

    $dup = q('SELECT id FROM leaves WHERE user_id = ? AND leave_type = ? AND start_date = ? AND end_date = ? AND part = ? AND (until_time <=> ?) LIMIT 1',
        [$user['id'], $type, $from, $to, $part, $until])->fetch();
    if ($dup) {
        return ['ok' => true, 'action' => 'unchanged', 'id' => (int)$dup['id'], 'error' => null, 'user' => $user['name']];
    }
    if ($dry) {
        return ['ok' => true, 'action' => 'created', 'id' => null, 'error' => null, 'user' => $user['name']];
    }
    q('INSERT INTO leaves (user_id, leave_type, start_date, end_date, part, until_time, reason, source, external_ref, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$user['id'], $type, $from, $to, $part, $until, $reason, $source, $ref !== '' ? $ref : null, $by]);
    $id = (int)db()->lastInsertId();
    recompute_late((int)$user['id'], $from, $to);
    return ['ok' => true, 'action' => 'created', 'id' => $id, 'error' => null, 'user' => $user['name']];
}

/** Remove a leave that came from another system (for example a withdrawn form). */
function leave_cancel_ref(string $source, string $ref, bool $dry = false): array
{
    $row = q('SELECT * FROM leaves WHERE source = ? AND external_ref = ?', [$source, $ref])->fetch();
    if (!$row) {
        return ['ok' => false, 'action' => 'failed', 'error' => 'Nothing found with that reference.', 'user' => null, 'id' => null];
    }
    if (!$dry) {
        q('DELETE FROM leaves WHERE id = ?', [$row['id']]);
        recompute_late((int)$row['user_id'], $row['start_date'], $row['end_date']);
    }
    return ['ok' => true, 'action' => 'cancelled', 'id' => (int)$row['id'], 'error' => null, 'user' => null];
}
