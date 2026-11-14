<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
if (!$u['track_attendance']) {
    redirect('/dashboard.php');
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$first = $month . '-01';
$last  = date('Y-m-t', strtotime($first));
$upTo  = min($last, date('Y-m-d'));

$recs = [];
foreach (q('SELECT * FROM attendance WHERE user_id = ? AND att_date BETWEEN ? AND ?', [$u['id'], $first, $last]) as $r) {
    $recs[$r['att_date']] = $r;
}
$hol = holidays_between($first, $last);
$wd  = working_day_numbers();
$lvMap = leaves_map([(int)$u['id']], $first, $last)[(int)$u['id']] ?? [];

$sum = ['present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0, 'minutes' => 0];
$rows = [];
for ($t = strtotime($first); $t <= strtotime($upTo); $t = strtotime('+1 day', $t)) {
    $d = date('Y-m-d', $t);
    $isWorkDay = in_array((int)date('N', $t), $wd, true) && !isset($hol[$d]);
    $r = $recs[$d] ?? null;
    $kind = isset($hol[$d]) ? 'holiday' : (!$isWorkDay ? 'off' : 'work');
    $lvAny = $lvMap[$d] ?? null;
    $lv    = away_for($lvAny);
    $half  = $lv && $lv['part'] !== 'full';
    if ($r && $r['status'] === 'leave') {
        $sum['leave'] += 1;
    } elseif ($r && $r['check_in']) {
        $sum[$r['status'] === 'late' ? 'late' : 'present']++;
        $sum['minutes'] += (int)net_minutes($r['check_in'], $r['check_out']);
        if ($half && $kind === 'work') { $sum['leave'] += 0.5; }
    } elseif ($lv && $kind === 'work') {
        $sum['leave']  += $half ? 0.5 : 1;
        $sum['absent'] += $half ? 0.5 : 0;
    } elseif ($kind === 'work') {
        $sum['absent'] += 1;
    }
    $rows[] = compact('d', 'r', 'kind', 'lvAny', 'lv', 'half');
}
$rows = array_reverse($rows);

render_header('My Attendance', 'my');
?>
<div class="grid grid-4">
  <div class="stat"><div class="num"><?= $sum['present'] + $sum['late'] ?></div><div class="lbl">Days present</div></div>
  <div class="stat late"><div class="num"><?= $sum['late'] ?></div><div class="lbl">Days late</div></div>
  <div class="stat info"><div class="num"><?= e(fmt_days((float)$sum['leave'])) ?></div><div class="lbl">Days on leave</div></div>
  <div class="stat bad"><div class="num"><?= e(fmt_days((float)$sum['absent'])) ?></div><div class="lbl">Days without record</div></div>
</div>

<div class="card">
  <form method="get" class="filters">
    <div class="field"><label for="month">Month</label>
      <input type="month" id="month" name="month" value="<?= e($month) ?>" data-autosubmit></div>
    <button class="btn btn-light" type="submit">Show</button>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Worked</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Nothing to show for this month yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $x): $r = $x['r']; ?>
      <tr class="<?= $x['kind'] === 'holiday' ? 'row-holiday' : ($x['kind'] === 'off' ? 'row-off' : '') ?>">
        <td class="nowrap"><?= e(fmt_date($x['d'])) ?></td>
        <?php if ($x['kind'] === 'holiday'): ?>
          <td colspan="4">Holiday — <?= e($hol[$x['d']]) ?></td>
        <?php elseif ($x['kind'] === 'off' && !$r): ?>
          <td colspan="4">Weekend / non-working day</td>
        <?php else: ?>
          <td><?= e(fmt_time($r['check_in'] ?? null)) ?></td>
          <td><?= e(fmt_time($r['check_out'] ?? null)) ?></td>
          <td><?= e(fmt_hm($r ? net_minutes($r['check_in'], $r['check_out']) : null)) ?></td>
          <td><?php if ($r && ($r['check_in'] || $r['status'] === 'leave')): ?><?= status_badge($r['status']) ?><?= $r['status'] === 'late' ? ' <span class="small muted">' . (int)$r['late_minutes'] . ' min</span>' : '' ?>
            <?php elseif ($x['lv']): ?><span class="badge badge-info"><?= e(leave_type_label($x['lv']['type'])) ?></span>
            <?php elseif ($r): ?><?= status_badge($r['status']) ?>
            <?php else: ?><span class="badge badge-bad">No record</span><?php endif; ?>
            <?php if ($x['lv'] && $x['half']): ?><div class="small muted"><?= $x['lv']['part'] === 'first_half' ? 'morning off' : 'afternoon off' ?> (0.5 day)</div><?php endif; ?>
            <?php if ($x['lvAny'] && $x['lvAny']['kind'] === 'permission'): ?><div class="small muted">permitted to arrive by <?= e(fmt_time($x['lvAny']['until'])) ?></div><?php endif; ?>
            <?php if ($r && $r['remarks']): ?><div class="small muted"><?= e($r['remarks']) ?></div><?php endif; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
