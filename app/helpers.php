<?php
declare(strict_types=1);

/* ---------------------------------------------------------------
 * Who can do what. Edit this map to change permissions.
 * ------------------------------------------------------------- */
const PERMS = [
    // add / edit / delete attendance, users, devices, holidays, settings
    'manage'        => ['sysadmin'],
    // full attendance reports for all staff
    'view_all'      => ['sysadmin', 'admin_officer', 'admin_assistant', 'president'],
    // late-comer list for every department
    'view_late_all' => ['sysadmin', 'admin_officer', 'admin_assistant', 'president', 'daa'],
    // late / absent list for the department(s) the user heads
    'view_late_dept'=> ['hod', 'section_head', 'dean'],
];

const ROLE_LABELS = [
    'sysadmin'        => 'System Administrator',
    'admin_officer'   => 'Administrative Officer',
    'admin_assistant' => 'Administrative Assistant',
    'president'       => 'President',
    'hod'             => 'Head of Department',
    'section_head'    => 'Section Head',
    'dean'            => 'Dean',
    'daa'             => 'Dean of Academic Affairs',
    'staff'           => 'Staff',
];

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------------- database ---------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = cfg('db');
    $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------------- settings ---------------- */
function settings(bool $refresh = false): array
{
    static $s = null;
    if ($s === null || $refresh) {
        $s = [];
        foreach (q('SELECT k, v FROM settings') as $r) {
            $s[$r['k']] = $r['v'];
        }
    }
    return $s;
}

function setting(string $k, string $default = ''): string
{
    return settings()[$k] ?? $default;
}

function save_setting(string $k, string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

/* ---------------- web helpers ---------------- */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Your session expired or the form was invalid. Go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function audit(string $action, string $details = ''): void
{
    $uid = $_SESSION['uid'] ?? null;
    q('INSERT INTO audit_log (user_id, action, details, ip_address, created_at) VALUES (?,?,?,?,?)',
        [$uid, $action, mb_substr($details, 0, 500), client_ip(), date('Y-m-d H:i:s')]);
}

/* ---------------- network ---------------- */
function ip_in_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if (strpos($cidr, '/') === false) {
        $cidr .= (strpos($cidr, ':') !== false) ? '/128' : '/32';
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipBin  = @inet_pton($ip);
    $subBin = @inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin) || !ctype_digit($bits)) {
        return false;
    }
    $bits = (int)$bits;
    if ($bits > strlen($ipBin) * 8) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
        return false;
    }
    if ($rem > 0) {
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($ipBin[$bytes]) & $mask) !== (ord($subBin[$bytes]) & $mask)) {
            return false;
        }
    }
    return true;
}

function valid_cidr(string $cidr): bool
{
    $cidr = trim($cidr);
    $parts = explode('/', $cidr, 2);
    if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
        return false;
    }
    if (isset($parts[1])) {
        $max = strpos($parts[0], ':') !== false ? 128 : 32;
        return ctype_digit($parts[1]) && (int)$parts[1] <= $max;
    }
    return true;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted = cfg('trusted_proxies', []);
    if ($trusted && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $viaTrusted = false;
        foreach ($trusted as $t) {
            if (ip_in_cidr($ip, $t)) {
                $viaTrusted = true;
                break;
            }
        }
        if ($viaTrusted) {
            $chain = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($chain as $cand) {
                if (!filter_var($cand, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $isProxy = false;
                foreach ($trusted as $t) {
                    if (ip_in_cidr($cand, $t)) {
                        $isProxy = true;
                        break;
                    }
                }
                if (!$isProxy) {
                    $ip = $cand;
                    break;
                }
            }
        }
    }
    if (stripos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }
    return $ip;
}

function office_networks(): array
{
    $raw = preg_split('/[\s,;]+/', setting('allowed_networks'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($raw, 'valid_cidr'));
}

function on_office_network(): bool
{
    $ip = client_ip();
    foreach (office_networks() as $cidr) {
        if (ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/* ---------------- permissions ---------------- */
function can(string $perm): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], PERMS[$perm] ?? [], true);
}

/**
 * Department ids a head looks after: their own department plus any extra ones
 * assigned to them on the Staff & Users page.
 * @return int[]
 */
function managed_department_ids(array $user): array
{
    $ids = [];
    if (!empty($user['department_id'])) {
        $ids[] = (int)$user['department_id'];
    }
    foreach (q('SELECT department_id FROM department_access WHERE user_id = ?', [$user['id']]) as $r) {
        $ids[] = (int)$r['department_id'];
    }
    return array_values(array_unique($ids));
}

/**
 * Departments the signed-in user may see attendance problems for.
 * null  = every department;  []  = none;  [ids] = only those.
 */
function visible_department_ids(array $user): ?array
{
    if (can('view_all') || can('view_late_all')) {
        return null;
    }
    if (can('view_late_dept')) {
        return managed_department_ids($user);
    }
    return [];
}

function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? $role;
}

/* ---------------- dates & schedule ---------------- */
function working_day_numbers(): array
{
    $n = array_map('intval', preg_split('/[\s,]+/', setting('working_days', '1,2,3,4,5'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    return $n ?: [1, 2, 3, 4, 5];
}

function holiday_title(string $date): ?string
{
    static $cache = [];
    if (!array_key_exists($date, $cache)) {
        $r = q('SELECT title FROM holidays WHERE holiday_date = ?', [$date])->fetch();
        $cache[$date] = $r ? $r['title'] : null;
    }
    return $cache[$date];
}

function holidays_between(string $from, string $to): array
{
    $out = [];
    foreach (q('SELECT holiday_date, title FROM holidays WHERE holiday_date BETWEEN ? AND ?', [$from, $to]) as $r) {
        $out[$r['holiday_date']] = $r['title'];
    }
    return $out;
}

function is_working_day(string $date): bool
{
    $dow = (int)date('N', strtotime($date));
    return in_array($dow, working_day_numbers(), true) && holiday_title($date) === null;
}

/** Working days between two dates, never beyond today. */
function working_days(string $from, string $to): array
{
    $to = min($to, date('Y-m-d'));
    $hol = holidays_between($from, $to);
    $days = [];
    $wd = working_day_numbers();
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        if (in_array((int)date('N', $t), $wd, true) && !isset($hol[$d])) {
            $days[] = $d;
        }
    }
    return $days;
}

function schedule_for(string $staffType): array
{
    return $staffType === 'teaching'
        ? [setting('teaching_start', '08:00'), setting('teaching_end', '17:30')]
        : [setting('nonteaching_start', '08:30'), setting('nonteaching_end', '16:30')];
}

function tmin(string $t): int
{
    $p = explode(':', $t);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

/** Minutes after official start time (0 = on time, respecting grace period). */
function late_minutes(string $checkIn, string $staffType): int
{
    [$start] = schedule_for($staffType);
    $diff = tmin($checkIn) - tmin($start);
    return $diff > (int)setting('grace_minutes', '0') ? $diff : 0;
}

/** Worked minutes, excluding the lunch break. */
function net_minutes(?string $in, ?string $out): ?int
{
    if (!$in || !$out) {
        return null;
    }
    $a = tmin($in);
    $b = tmin($out);
    if ($b <= $a) {
        return 0;
    }
    $ls = tmin(setting('lunch_start', '12:00'));
    $le = tmin(setting('lunch_end', '13:00'));
    $overlap = max(0, min($b, $le) - max($a, $ls));
    return $b - $a - $overlap;
}

function fmt_hm(?int $minutes): string
{
    return $minutes === null ? '—' : intdiv($minutes, 60) . 'h ' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

function fmt_time(?string $t): string
{
    return $t ? date('g:i A', strtotime('1970-01-01 ' . $t)) : '—';
}

function fmt_date(string $d): string
{
    return date('D, d M Y', strtotime($d));
}

function valid_date(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x !== false && $x->format('Y-m-d') === $d;
}

function valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
}

function status_badge(string $status): string
{
    $map = [
        'present' => ['ok', 'On time'],
        'late'    => ['warn', 'Late'],
        'leave'   => ['info', 'On leave'],
        'absent'  => ['bad', 'Absent'],
    ];
    [$cls, $label] = $map[$status] ?? ['muted', ucfirst($status)];
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}

/* ---------------- CSV ---------------- */
function csv_download(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    $clean = static function ($v) {
        $v = (string)$v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, array_map($clean, $r));
    }
    fclose($out);
    exit;
}

function icon(string $name): string
{
    static $paths = [
        'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'device'   => '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'building' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><line x1="8" y1="6" x2="8.01" y2="6"/><line x1="12" y1="6" x2="12.01" y2="6"/><line x1="16" y1="6" x2="16.01" y2="6"/><line x1="8" y1="10" x2="8.01" y2="10"/><line x1="12" y1="10" x2="12.01" y2="10"/><line x1="16" y1="10" x2="16.01" y2="10"/>',
        'settings' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'log'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'menu'     => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'wifi'     => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}
