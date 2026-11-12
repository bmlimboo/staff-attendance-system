<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_any_perm(['view_late_all', 'view_late_dept']);

$seeAll = can('view_late_all');
$today  = date('Y-m-d');

$from = $_GET['from'] ?? $today;
$to   = $_GET['to'] ?? $today;
if (!valid_date($from)) { $from = $today; }
if (!valid_date($to))   { $to = $today; }
if ($to < $from)        { [$from, $to] = [$to, $from]; }

$deptId = $seeAll ? (int)($_GET['dept'] ?? 0) : (int)$u['department_id'];
if (!$seeAll && !$deptId) {
    render_header('Late Comers', 'late');
    echo '<div class="alert alert-warn">Your account is not linked to a department yet. Ask the system administrator to set your department.</div>';
    render_footer();
    exit;
}

$sql = "SELECT a.att_date, a.check_in, a.late_minutes, u.id AS uid, u.name, u.staff_id, u.staff_type, d.name AS dept
        FROM attendance a JOIN users u ON u.id = a.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE a.status = 'late' AND a.att_date BETWEEN ? AND ?";
$p = [$from, $to];
if ($deptId) {
    $sql .= ' AND u.department_id = ?';
    $p[] = $deptId;
}
$sql .= ' ORDER BY a.att_date DESC, a.late_minutes DESC, u.name';
$rows = q($sql, $p)->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    audit('export_late', "$from to $to");
    csv_download("late-comers_{$from}_to_{$to}.csv",
        ['Date', 'Staff ID', 'Name', 'Department', 'Type', 'Check-in', 'Minutes late'],
        array_map(fn($r) => [$r['att_date'], $r['staff_id'], $r['name'], $r['dept'] ?? '',
            $r['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching', substr($r['check_in'], 0, 5), $r['late_minutes']], $rows));
}

$byStaff = [];
foreach ($rows as $r) {
    $k = $r['uid'];
    $byStaff[$k] ??= ['name' => $r['name'], 'dept' => $r['dept'], 'days' => 0, 'mins' => 0];
    $byStaff[$k]['days']++;
    $byStaff[$k]['mins'] += (int)$r['late_minutes'];
}
uasort($byStaff, fn($a, $b) => [$b['days'], $b['mins']] <=> [$a['days'], $a['mins']]);

$depts = $seeAll ? q('SELECT id, name FROM departments ORDER BY name')->fetchAll() : [];
$qs = http_build_query(['from' => $from, 'to' => $to, 'dept' => $deptId, 'export' => 'csv']);

render_header('Late Comers', 'late');
?>
<div class="card">
  <div class="card-head">
    <h2><?= $seeAll ? 'Late comers — all departments' : 'Late comers — ' . e($u['dept_name'] ?? 'my department') ?></h2>
    <a class="btn btn-light btn-sm" href="?<?= e($qs) ?>">Export CSV</a>
  </div>
  <form method="get" class="filters">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <?php if ($seeAll): ?>
    <div class="field"><label for="dept">Department</label>
      <select id="dept" name="dept"><option value="0">All departments</option>
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <button class="btn" type="submit">Show</button>
  </form>

  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Staff</th><?php if ($seeAll): ?><th>Department</th><?php endif; ?><th>Type</th><th>Check-in</th><th>Late by</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No late arrivals in this period.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(fmt_date($r['att_date'])) ?></td>
        <td><strong><?= e($r['name']) ?></strong> <span class="small muted"><?= e($r['staff_id']) ?></span></td>
        <?php if ($seeAll): ?><td><?= e($r['dept'] ?? '—') ?></td><?php endif; ?>
        <td><?= $r['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching' ?></td>
        <td class="nowrap"><?= e(fmt_time($r['check_in'])) ?></td>
        <td><span class="badge badge-warn"><?= (int)$r['late_minutes'] ?> min</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if (count($byStaff) > 0 && $from !== $to): ?>
<div class="card">
  <h2>Who is late most often</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><?php if ($seeAll): ?><th>Department</th><?php endif; ?><th>Days late</th><th>Total minutes late</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($byStaff, 0, 15) as $s): ?>
      <tr><td><strong><?= e($s['name']) ?></strong></td><?php if ($seeAll): ?><td><?= e($s['dept'] ?? '—') ?></td><?php endif; ?>
        <td><?= (int)$s['days'] ?></td><td><?= (int)$s['mins'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php render_footer();
