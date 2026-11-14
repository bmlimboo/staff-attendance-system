<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_any_perm(['view_late_all', 'view_late_dept']);

$today   = date('Y-m-d');
$visible = visible_department_ids($u);   // null = every department

if ($visible !== null && !$visible) {
    render_header('Late & Absent', 'late');
    echo '<div class="alert alert-warn">Your account is not linked to a department yet. Ask the system administrator to set your department.</div>';
    render_footer();
    exit;
}

$from = $_GET['from'] ?? $today;
$to   = $_GET['to'] ?? $today;
if (!valid_date($from)) { $from = $today; }
if (!valid_date($to))   { $to = $today; }
if ($to < $from)        { [$from, $to] = [$to, $from]; }
if (strtotime($to) - strtotime($from) > 366 * 86400) { $from = date('Y-m-d', strtotime('-366 days', strtotime($to))); }

$show = in_array($_GET['show'] ?? '', ['late', 'absent', 'away'], true) ? $_GET['show'] : 'both';

/* departments this person may look at */
$allDepts = q('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$depts = $visible === null ? $allDepts
    : array_values(array_filter($allDepts, fn($d) => in_array((int)$d['id'], $visible, true)));
$allowedIds = array_map(fn($d) => (int)$d['id'], $depts);

$deptId = (int)($_GET['dept'] ?? 0);
if ($deptId && !in_array($deptId, $allowedIds, true)) { $deptId = 0; }

/* staff in scope */
$sql = 'SELECT u.id, u.name, u.staff_id, u.staff_type, u.designation, u.department_id, d.name AS dept
        FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.active = 1 AND u.track_attendance = 1';
$p = [];
if ($deptId) {
    $sql .= ' AND u.department_id = ?';
    $p[] = $deptId;
} elseif ($visible !== null) {
    $sql .= ' AND u.department_id IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . ')';
    $p = array_merge($p, $allowedIds);
}
$sql .= ' ORDER BY d.name, u.name';
$staff = q($sql, $p)->fetchAll();

/* attendance in the period */
$recs = [];
foreach (q('SELECT user_id, att_date, status, check_in, late_minutes FROM attendance WHERE att_date BETWEEN ? AND ?', [$from, $to]) as $r) {
    $recs[$r['user_id']][$r['att_date']] = $r;
}
$days   = working_days($from, $to);
$nowMin = tmin(date('H:i'));
$leaves = leaves_map(array_column($staff, 'id'), $from, $to);
$lunchEnd = tmin(setting('lunch_end', '13:00'));

$rows = [];
$perStaff = [];
foreach ($staff as $s) {
    [$start] = schedule_for($s['staff_type']);
    foreach ($days as $d) {
        $r = $recs[$s['id']][$d] ?? null;
        $status = $r['status'] ?? null;
        $lv   = away_for($leaves[$s['id']][$d] ?? null);   // leave / tour (not a late permission)
        $half = $lv && $lv['part'] !== 'full';
        $label = null;

        if ($show === 'away') {                              // who was on leave or official duty
            if ($lv) {
                $label = leave_public_label($lv['type'], $lv['part']);
            } elseif ($status === 'leave') {
                $label = 'On leave';
            } else {
                continue;
            }
            $rows[] = ['s' => $s, 'date' => $d, 'kind' => 'away', 'in' => $r['check_in'] ?? null, 'min' => 0, 'note' => '', 'label' => $label];
            continue;
        }

        if ($status === 'leave' || $status === 'present') { continue; }
        $note = '';
        if ($status === 'late') {
            if ($show === 'absent') { continue; }
            $kind = 'late';
        } else { // no record, or explicitly marked absent
            if ($show === 'late') { continue; }
            if ($lv && !$half) { continue; }                 // whole day on leave / tour: not absent
            $base = ($lv && $lv['part'] === 'first_half') ? $lunchEnd : tmin($start);
            if (!$r && $d === $today && $nowMin <= $base) { continue; }   // not expected yet today
            $kind = (!$r && $d === $today) ? 'pending' : 'absent';
            if ($half) { $note = 'other half of the day (half-day leave)'; }
        }
        $rows[] = ['s' => $s, 'date' => $d, 'kind' => $kind, 'in' => $r['check_in'] ?? null, 'min' => (int)($r['late_minutes'] ?? 0), 'note' => $note, 'label' => null];

        $k = $s['id'];
        $perStaff[$k] ??= ['s' => $s, 'late' => 0, 'late_min' => 0, 'absent' => 0];
        if ($kind === 'late')   { $perStaff[$k]['late']++; $perStaff[$k]['late_min'] += (int)$r['late_minutes']; }
        if ($kind === 'absent') { $perStaff[$k]['absent'] += $half ? 0.5 : 1; }
    }
}
usort($rows, fn($a, $b) => [(string)$a['s']['dept'], $b['date'], $a['s']['name']] <=> [(string)$b['s']['dept'], $a['date'], $b['s']['name']]);
uasort($perStaff, fn($a, $b) => [$b['late'] + $b['absent'], $b['late_min']] <=> [$a['late'] + $a['absent'], $a['late_min']]);

$totLate = count(array_filter($rows, fn($r) => $r['kind'] === 'late'));
$totAbs  = array_sum(array_map(fn($r) => $r['note'] !== '' ? 0.5 : 1, array_filter($rows, fn($r) => $r['kind'] === 'absent')));
$totAway = count(array_filter($rows, fn($r) => $r['kind'] === 'away'));
$totPend = count(array_filter($rows, fn($r) => $r['kind'] === 'pending'));

$kindLabel = ['late' => 'Late', 'absent' => 'Absent', 'pending' => 'Not in yet', 'away' => 'Away'];

if (($_GET['export'] ?? '') === 'csv') {
    audit('export_late_absent', "$from to $to");
    csv_download("late-and-absent_{$from}_to_{$to}.csv",
        ['Date', 'Department', 'Staff ID', 'Name', 'Designation', 'Type', 'Status', 'Check-in', 'Minutes late'],
        array_map(fn($r) => [$r['date'], $r['s']['dept'] ?? '', $r['s']['staff_id'], $r['s']['name'], $r['s']['designation'],
            $r['s']['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching', ($r['label'] ?? $kindLabel[$r['kind']]),
            $r['in'] ? substr($r['in'], 0, 5) : '', $r['kind'] === 'late' ? $r['min'] : ''], $rows));
}

$qs = http_build_query(['from' => $from, 'to' => $to, 'dept' => $deptId, 'show' => $show, 'export' => 'csv']);
$title = $visible === null ? 'all departments' : (count($allowedIds) === 1 ? $depts[0]['name'] : 'my departments');

render_header('Late & Absent', 'late');
?>
<div class="grid grid-3">
  <div class="stat late"><div class="num"><?= $totLate ?></div><div class="lbl">Late arrivals</div></div>
  <div class="stat bad"><div class="num"><?= e(fmt_days((float)$totAbs)) ?></div><div class="lbl">Absent days (no record, no leave)</div></div>
  <div class="stat info"><div class="num"><?= count($staff) ?></div><div class="lbl">Staff in view</div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Late &amp; absent — <?= e($title) ?></h2>
    <a class="btn btn-light btn-sm" href="?<?= e($qs) ?>">Export CSV</a>
  </div>
  <form method="get" class="filters">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label for="dept">Department</label>
      <select id="dept" name="dept"><option value="0"><?= $visible === null ? 'All departments' : 'All of mine' ?></option>
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="show">Show</label>
      <select id="show" name="show">
        <option value="both" <?= $show === 'both' ? 'selected' : '' ?>>Late and absent</option>
        <option value="late" <?= $show === 'late' ? 'selected' : '' ?>>Late only</option>
        <option value="absent" <?= $show === 'absent' ? 'selected' : '' ?>>Absent only</option>
        <option value="away" <?= $show === 'away' ? 'selected' : '' ?>>On leave / official duty</option></select></div>
    <button class="btn" type="submit">Show</button>
  </form>
  <p class="small muted">"Absent" means a working day with no check-in and no leave recorded. Leave, tours, training, holidays and weekends are not counted as absent. <?= $totPend ? "$totPend person(s) have not checked in yet today." : '' ?></p>

  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Staff</th><th>Designation</th><th>Type</th><th>Status</th><th>Check-in</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nobody was late or absent in this period.</td></tr><?php endif; ?>
    <?php $lastDept = false; foreach ($rows as $r): $dn = $r['s']['dept'] ?? 'No department'; ?>
      <?php if ($dn !== $lastDept): $lastDept = $dn; ?>
        <tr class="row-group"><td colspan="6"><?= e($dn) ?></td></tr>
      <?php endif; ?>
      <tr>
        <td class="nowrap"><?= e(fmt_date($r['date'])) ?></td>
        <td><strong><?= e($r['s']['name']) ?></strong> <span class="small muted"><?= e($r['s']['staff_id']) ?></span></td>
        <td><?= e($r['s']['designation'] ?: '—') ?></td>
        <td><?= $r['s']['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching' ?></td>
        <td>
          <?php if ($r['kind'] === 'late'): ?><span class="badge badge-warn">Late <?= (int)$r['min'] ?> min</span>
          <?php elseif ($r['kind'] === 'absent'): ?><span class="badge badge-bad">Absent</span><?= $r['note'] !== '' ? '<div class="small muted">' . e($r['note']) . '</div>' : '' ?>
          <?php elseif ($r['kind'] === 'away'): ?><span class="badge badge-info"><?= e($r['label']) ?></span>
          <?php else: ?><span class="badge badge-info">Not in yet</span><?php endif; ?>
        </td>
        <td class="nowrap"><?= e(fmt_time($r['in'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($perStaff && $from !== $to && $show !== 'away'): ?>
<div class="card">
  <h2>Who is late or absent most often</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Designation</th><th>Department</th><th>Days late</th><th>Minutes late</th><th>Days absent</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($perStaff, 0, 20) as $x): ?>
      <tr>
        <td><strong><?= e($x['s']['name']) ?></strong></td>
        <td><?= e($x['s']['designation'] ?: '—') ?></td>
        <td><?= e($x['s']['dept'] ?? '—') ?></td>
        <td><?= (int)$x['late'] ?></td><td><?= (int)$x['late_min'] ?></td>
        <td><?= $x['absent'] ? '<span class="badge badge-bad">' . e(fmt_days((float)$x['absent'])) . '</span>' : 0 ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php render_footer();
