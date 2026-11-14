<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_perm('view_all');

$today = date('Y-m-d');
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to'] ?? $today;
if (!valid_date($from)) { $from = date('Y-m-01'); }
if (!valid_date($to))   { $to = $today; }
if ($to < $from)        { [$from, $to] = [$to, $from]; }

$view   = ($_GET['view'] ?? 'summary') === 'detail' ? 'detail' : 'summary';
$deptId = (int)($_GET['dept'] ?? 0);
$type   = in_array($_GET['type'] ?? '', ['teaching', 'non_teaching'], true) ? $_GET['type'] : '';
$userId = (int)($_GET['user'] ?? 0);

/* ----- staff in scope ----- */
$sql = 'SELECT u.id, u.name, u.staff_id, u.staff_type, u.designation, d.name AS dept
        FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.active = 1 AND u.track_attendance = 1';
$p = [];
if ($deptId) { $sql .= ' AND u.department_id = ?'; $p[] = $deptId; }
if ($type)   { $sql .= ' AND u.staff_type = ?';    $p[] = $type; }
if ($userId) { $sql .= ' AND u.id = ?';            $p[] = $userId; }
$sql .= ' ORDER BY d.name, u.name';
$staff = q($sql, $p)->fetchAll();

/* ----- records ----- */
$recs = [];   // [user_id][date] => row
foreach (q('SELECT * FROM attendance WHERE att_date BETWEEN ? AND ?', [$from, $to]) as $r) {
    $recs[$r['user_id']][$r['att_date']] = $r;
}
$days = working_days($from, $to);
$dayCount = count($days);

/* ----- summary ----- */
$summary = [];
foreach ($staff as $s) {
    $row = ['s' => $s, 'present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0, 'late_min' => 0, 'worked' => 0];
    foreach ($days as $d) {
        $r = $recs[$s['id']][$d] ?? null;
        if ($r && $r['status'] === 'leave') {
            $row['leave']++;
        } elseif ($r && $r['check_in']) {
            $row[$r['status'] === 'late' ? 'late' : 'present']++;
            $row['late_min'] += (int)$r['late_minutes'];
            $row['worked']   += (int)net_minutes($r['check_in'], $r['check_out']);
        } else {
            $row['absent']++;
        }
    }
    $summary[] = $row;
}

/* ----- detail ----- */
$detail = [];
if ($view === 'detail') {
    $ids = array_column($staff, 'id');
    $byId = array_column($staff, null, 'id');
    foreach ($recs as $uid => $dates) {
        if (!isset($byId[$uid])) { continue; }
        foreach ($dates as $d => $r) {
            $detail[] = ['s' => $byId[$uid], 'r' => $r];
        }
    }
    usort($detail, fn($a, $b) => [$b['r']['att_date'], $a['s']['name']] <=> [$a['r']['att_date'], $b['s']['name']]);
}

/* ----- CSV ----- */
if (($_GET['export'] ?? '') === 'csv') {
    audit('export_report', "$view $from to $to");
    if ($view === 'summary') {
        csv_download("attendance-summary_{$from}_to_{$to}.csv",
            ['Staff ID', 'Name', 'Designation', 'Department', 'Type', 'Working days', 'On time', 'Late', 'Leave', 'No record', 'Total minutes late', 'Hours worked'],
            array_map(fn($x) => [$x['s']['staff_id'], $x['s']['name'], $x['s']['designation'], $x['s']['dept'] ?? '',
                $x['s']['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching', $dayCount,
                $x['present'], $x['late'], $x['leave'], $x['absent'], $x['late_min'], round($x['worked'] / 60, 1)], $summary));
    }
    csv_download("attendance-detail_{$from}_to_{$to}.csv",
        ['Date', 'Staff ID', 'Name', 'Designation', 'Department', 'Check-in', 'Check-out', 'Hours worked', 'Status', 'Minutes late', 'Source', 'Remarks'],
        array_map(fn($x) => [$x['r']['att_date'], $x['s']['staff_id'], $x['s']['name'], $x['s']['designation'], $x['s']['dept'] ?? '',
            substr((string)$x['r']['check_in'], 0, 5), substr((string)$x['r']['check_out'], 0, 5),
            ($m = net_minutes($x['r']['check_in'], $x['r']['check_out'])) === null ? '' : round($m / 60, 2),
            $x['r']['status'], $x['r']['late_minutes'], $x['r']['source'], $x['r']['remarks']], $detail));
}

$depts   = q('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$allStaff = q('SELECT id, name FROM users WHERE active = 1 AND track_attendance = 1 ORDER BY name')->fetchAll();
$exportQs = http_build_query(['from' => $from, 'to' => $to, 'dept' => $deptId, 'type' => $type, 'user' => $userId, 'view' => $view, 'export' => 'csv']);

render_header('Attendance Reports', 'reports');
?>
<div class="card">
  <div class="card-head"><h2>Report</h2><a class="btn btn-light btn-sm" href="?<?= e($exportQs) ?>">Export CSV</a></div>
  <form method="get" class="filters">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label for="dept">Department</label>
      <select id="dept" name="dept"><option value="0">All</option>
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="type">Staff type</label>
      <select id="type" name="type"><option value="">All</option>
        <option value="teaching" <?= $type === 'teaching' ? 'selected' : '' ?>>Teaching</option>
        <option value="non_teaching" <?= $type === 'non_teaching' ? 'selected' : '' ?>>Non-teaching</option></select></div>
    <div class="field"><label for="user">Staff member</label>
      <select id="user" name="user"><option value="0">Everyone</option>
        <?php foreach ($allStaff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $userId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="view">View</label>
      <select id="view" name="view">
        <option value="summary" <?= $view === 'summary' ? 'selected' : '' ?>>Summary per staff</option>
        <option value="detail" <?= $view === 'detail' ? 'selected' : '' ?>>Day-by-day records</option></select></div>
    <button class="btn" type="submit">Apply</button>
  </form>
  <p class="small muted"><?= $dayCount ?> working day(s) in this period (weekends and college holidays excluded).</p>

  <?php if ($view === 'summary'): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Department</th><th>Type</th><th>On time</th><th>Late</th><th>Leave</th><th>No record</th><th>Min. late</th><th>Hours worked</th></tr></thead>
    <tbody>
    <?php if (!$summary): ?><tr><td colspan="9" class="empty">No staff match these filters.</td></tr><?php endif; ?>
    <?php foreach ($summary as $x): ?>
      <tr>
        <td><strong><?= e($x['s']['name']) ?></strong> <span class="small muted"><?= e($x['s']['staff_id']) ?></span><?= $x['s']['designation'] !== '' ? '<div class="small muted">' . e($x['s']['designation']) . '</div>' : '' ?></td>
        <td><?= e($x['s']['dept'] ?? '—') ?></td>
        <td><?= $x['s']['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching' ?></td>
        <td><?= $x['present'] ?></td>
        <td><?= $x['late'] ? '<span class="badge badge-warn">' . $x['late'] . '</span>' : 0 ?></td>
        <td><?= $x['leave'] ?></td>
        <td><?= $x['absent'] ? '<span class="badge badge-bad">' . $x['absent'] . '</span>' : 0 ?></td>
        <td><?= $x['late_min'] ?></td>
        <td><?= e(fmt_hm($x['worked'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Staff</th><th>Department</th><th>Check-in</th><th>Check-out</th><th>Worked</th><th>Status</th><th>Source</th></tr></thead>
    <tbody>
    <?php if (!$detail): ?><tr><td colspan="8" class="empty">No records in this period.</td></tr><?php endif; ?>
    <?php foreach ($detail as $x): $r = $x['r']; ?>
      <tr>
        <td class="nowrap"><?= e(fmt_date($r['att_date'])) ?></td>
        <td><strong><?= e($x['s']['name']) ?></strong> <span class="small muted"><?= e($x['s']['staff_id']) ?></span><?= $x['s']['designation'] !== '' ? '<div class="small muted">' . e($x['s']['designation']) . '</div>' : '' ?></td>
        <td><?= e($x['s']['dept'] ?? '—') ?></td>
        <td><?= e(fmt_time($r['check_in'])) ?></td>
        <td><?= e(fmt_time($r['check_out'])) ?></td>
        <td><?= e(fmt_hm(net_minutes($r['check_in'], $r['check_out']))) ?></td>
        <td><?= status_badge($r['status']) ?><?= $r['status'] === 'late' ? ' <span class="small muted">' . (int)$r['late_minutes'] . ' min</span>' : '' ?></td>
        <td class="small muted"><?= $r['source'] === 'admin' ? 'Edited by admin' : 'Automatic' ?><?= $r['remarks'] ? ' · ' . e($r['remarks']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
