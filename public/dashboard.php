<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$today   = date('Y-m-d');
$isWork  = is_working_day($today);
$holiday = holiday_title($today);

/* ---- result of the automatic check-in done at login ---- */
$result = $_SESSION['checkin_result'] ?? null;
unset($_SESSION['checkin_result']);

$banner = null;
if ($result) {
    switch ($result['code']) {
        case 'checked_in':
            $late = (int)$result['late'];
            $banner = $late > 0
                ? ['warn', 'Attendance recorded at ' . fmt_time($result['time']) . ". You are $late minute(s) late today."]
                : ['ok', 'Attendance recorded at ' . fmt_time($result['time']) . '. You are on time — thank you!'];
            break;
        case 'already':
            $banner = ['info', 'You already checked in today at ' . fmt_time($result['time']) . '.'];
            break;
        case 'offsite':
            $banner = ['warn', 'You are outside the college network, so no attendance was recorded.'];
            break;
        case 'device_none':
            $banner = ['warn', 'This device is not registered, so no attendance was recorded. <a href="/register_device.php">Register this device</a>.'];
            break;
        case 'device_pending':
            $banner = ['info', 'This device is waiting for administrator approval. Attendance will start once it is approved.'];
            break;
        case 'device_rejected':
        case 'device_revoked':
            $banner = ['error', 'This device is not allowed to record attendance. Please contact the system administrator.'];
            break;
        case 'non_working':
            $banner = ['info', $result['holiday'] ? 'Today is a holiday: ' . $result['holiday'] . '. No attendance needed.' : (date('N') >= 6 ? 'Today is the weekend. No attendance is needed.' : 'Today is not a working day.')];
            break;
        case 'leave':
            $banner = ['info', 'You are marked on leave today.'];
            break;
    }
}

/* ---- my record today ---- */
$mine = null;
if ($u['track_attendance']) {
    $mine = q('SELECT * FROM attendance WHERE user_id = ? AND att_date = ?', [$u['id'], $today])->fetch() ?: null;
}
[$sStart, $sEnd] = schedule_for($u['staff_type']);

/* ---- scope for leadership views ---- */
$seeAll   = can('view_all') || can('view_late_all');
$visible  = visible_department_ids($u);            // null = all departments
$showTeam = $seeAll || !empty($visible);

$scopeSql = ' u.active = 1 AND u.track_attendance = 1 ';
$scopeP   = [];
if (!$seeAll && $visible) {
    $scopeSql .= ' AND u.department_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ') ';
    $scopeP    = $visible;
}

$lateToday = $notIn = $awayToday = [];
$nowMin = tmin(date('H:i'));
$stat = ['total' => 0, 'in' => 0, 'late' => 0, 'leave' => 0];
$trend = [];
if ($showTeam) {
    $stat['total'] = (int)q("SELECT COUNT(*) c FROM users u WHERE $scopeSql", $scopeP)->fetch()['c'];

    $rows = q("SELECT a.*, u.name, u.staff_id, u.staff_type, u.designation, d.name AS dept
               FROM attendance a JOIN users u ON u.id = a.user_id
               LEFT JOIN departments d ON d.id = u.department_id
               WHERE a.att_date = ? AND $scopeSql ORDER BY a.late_minutes DESC, a.check_in",
        array_merge([$today], $scopeP))->fetchAll();
    // who is on leave / tour today: leave records plus any day marked "leave" in the attendance list
    $scopeIds = array_column(q("SELECT u.id FROM users u WHERE $scopeSql", $scopeP)->fetchAll(), 'id');
    $awayUids = [];   // uid => leave entry (or a plain "on leave")
    foreach (leaves_map($scopeIds, $today, $today) as $uid => $byDay) {
        if ($lv = away_for($byDay[$today] ?? null)) {
            $awayUids[(int)$uid] = $lv;
        }
    }
    foreach ($rows as $r) {
        if ($r['status'] === 'leave') {
            $awayUids[(int)$r['user_id']] ??= ['type' => '', 'part' => 'full', 'kind' => 'leave'];
        } elseif ($r['check_in']) {
            $stat['in']++;
            if ($r['status'] === 'late') {
                $stat['late']++;
                $lateToday[] = $r;
            }
        }
    }
    $stat['leave'] = count($awayUids);
    if ($isWork) {
        $notIn = q("SELECT u.id, u.name, u.staff_id, u.staff_type, u.designation, d.name AS dept
                    FROM users u LEFT JOIN departments d ON d.id = u.department_id
                    WHERE $scopeSql AND u.id NOT IN (SELECT user_id FROM attendance WHERE att_date = ? AND (check_in IS NOT NULL OR status IN ('leave','present','late')))
                    ORDER BY d.name, u.name", array_merge($scopeP, [$today]))->fetchAll();
        // people on leave or tour are not "missing"; a morning half-day is expected after lunch
        $notIn = array_values(array_filter($notIn, function ($r) use ($awayUids, $nowMin) {
            $lv = $awayUids[(int)$r['id']] ?? null;
            if (!$lv) { return true; }                                     // not on leave: still missing
            if ($lv['part'] === 'second_half') { return true; }            // afternoon off, but expected this morning
            if ($lv['part'] === 'first_half') { return $nowMin > tmin(setting('lunch_end', '13:00')); }
            return false;                                                  // whole day off
        }));
        if ($awayUids) {
            $ph2 = implode(',', array_fill(0, count($awayUids), '?'));
            foreach (q("SELECT u.id, u.name, u.designation, d.name AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
                        WHERE u.id IN ($ph2) ORDER BY d.name, u.name", array_keys($awayUids))->fetchAll() as $r) {
                $lv = $awayUids[(int)$r['id']];
                $r['label'] = $lv['type'] !== '' ? leave_public_label($lv['type'], $lv['part']) : 'On leave';
                $awayToday[] = $r;
            }
        }
    }

    // late count for the last 10 working days
    $days = array_slice(working_days(date('Y-m-d', strtotime('-20 days')), $today), -10);
    if ($days) {
        $ph = implode(',', array_fill(0, count($days), '?'));
        $cnt = [];
        foreach (q("SELECT a.att_date, COUNT(*) c FROM attendance a JOIN users u ON u.id = a.user_id
                    WHERE a.status = 'late' AND a.att_date IN ($ph) AND $scopeSql GROUP BY a.att_date",
                 array_merge($days, $scopeP)) as $r) {
            $cnt[$r['att_date']] = (int)$r['c'];
        }
        foreach ($days as $d) {
            $trend[$d] = $cnt[$d] ?? 0;
        }
    }
}
$pendingDevices = can('manage') ? (int)q("SELECT COUNT(*) c FROM devices WHERE status = 'pending'")->fetch()['c'] : 0;
$trendMax = $trend ? max(1, max($trend)) : 1;

render_header('Dashboard', 'dashboard');

if ($banner) {
    // Only the static "register this device" message is allowed to contain markup.
    $isHtml = ($result['code'] ?? '') === 'device_none';
    echo '<div class="alert alert-' . e($banner[0]) . '">' . ($isHtml ? $banner[1] : e($banner[1])) . '</div>';
}
$myAway = $u['track_attendance'] ? away_for(leave_for((int)$u['id'], $today)) : null;
if ($myAway && $isWork) {
    echo '<div class="alert alert-info">You are recorded as <strong>' . e(leave_type_label($myAway['type'])) . '</strong>'
        . ($myAway['part'] !== 'full' ? ' (' . ($myAway['part'] === 'first_half' ? 'morning' : 'afternoon') . ' off)' : '') . ' today.</div>';
}
if ($pendingDevices > 0) {
    echo '<div class="alert alert-info"><strong>' . $pendingDevices . '</strong> device request(s) waiting for approval. <a href="/admin/devices.php">Review now</a></div>';
}
?>
<div class="hero">
  <div>
    <h2>Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $u['name'])[0]) ?></h2>
    <p><?= e(date('l, d F Y')) ?> ·
      <?php if ($holiday): ?>Holiday: <?= e($holiday) ?>
      <?php elseif (!$isWork): ?><?= date('N') >= 6 ? 'Weekend' : 'Not a working day' ?>
      <?php else: ?>Office hours <?= e(fmt_time($sStart)) ?> – <?= e(fmt_time($sEnd)) ?> · Lunch <?= e(fmt_time(setting('lunch_start', '12:00'))) ?> – <?= e(fmt_time(setting('lunch_end', '13:00'))) ?><?php endif; ?>
    </p>
  </div>
  <?php if ($u['track_attendance']): ?>
  <div class="times">
    <div><span>Check-in</span><strong><?= e(fmt_time($mine['check_in'] ?? null)) ?></strong></div>
    <div><span>Check-out</span><strong><?= e(fmt_time($mine['check_out'] ?? null)) ?></strong></div>
    <div><span>Status</span><strong><?= $mine ? e(['present' => 'On time', 'late' => 'Late', 'leave' => 'Leave', 'absent' => 'Absent'][$mine['status']]) : ($isWork ? 'Not recorded' : '—') ?></strong></div>
  </div>
  <?php if ($mine && $mine['check_in']): ?>
    <form method="post" action="/checkout.php">
      <?= csrf_field() ?>
      <button class="btn btn-gold" type="submit"><?= icon('logout') ?> Check out</button>
    </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($showTeam): ?>
<div class="grid grid-4">
  <div class="stat"><div class="num"><?= $stat['total'] ?></div><div class="lbl"><?= $seeAll ? 'Staff tracked' : (count($visible) === 1 ? 'Staff in ' . e($u['dept_name'] ?? 'your department') : 'Staff in your departments') ?></div></div>
  <div class="stat"><div class="num"><?= $stat['in'] ?></div><div class="lbl">Checked in today</div></div>
  <div class="stat late"><div class="num"><?= $stat['late'] ?></div><div class="lbl">Late today</div></div>
  <div class="stat info"><div class="num"><?= $stat['leave'] ?></div><div class="lbl">On leave</div></div>
</div>

<div class="grid grid-2" style="grid-template-columns:1.6fr 1fr">
  <div class="card">
    <div class="card-head"><h2>Late today</h2><a class="btn btn-light btn-sm" href="/late.php">View history</a></div>
    <?php if (!$isWork): ?>
      <p class="empty">No attendance is expected today.</p>
    <?php elseif (!$lateToday): ?>
      <p class="empty">Nobody has been late so far today. 🎉</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Staff</th><th>Department</th><th>Arrived</th><th>Late by</th></tr></thead>
      <tbody>
      <?php foreach ($lateToday as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong><div class="small muted"><?= e($r['designation'] ?: ($r['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching')) ?> · <?= e($r['staff_id']) ?></div></td>
          <td><?= e($r['dept'] ?? '—') ?></td>
          <td class="nowrap"><?= e(fmt_time($r['check_in'])) ?></td>
          <td><span class="badge badge-warn"><?= (int)$r['late_minutes'] ?> min</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Late arrivals — recent days</h2>
    <?php if (!$trend): ?><p class="empty">No data yet.</p><?php else: ?>
    <div class="bars">
      <?php foreach ($trend as $d => $c): ?>
        <div class="bar"><b><?= $c ?></b><i style="height:<?= max(3, round($c / $trendMax * 100)) ?>%"></i><span><?= e(date('d M', strtotime($d))) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isWork && $awayToday): ?>
<div class="card">
  <div class="card-head"><h2>On leave or official duty today (<?= count($awayToday) ?>)</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Designation</th><th>Department</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($awayToday as $r): ?>
      <tr><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['designation'] ?: '—') ?></td><td><?= e($r['dept'] ?? '—') ?></td><td><span class="badge badge-info"><?= e($r['label']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($isWork): ?>
<div class="card">
  <div class="card-head"><h2>Not checked in yet / absent (<?= count($notIn) ?>)</h2><a class="btn btn-light btn-sm" href="/late.php?show=absent">Absent history</a></div>
  <?php if (!$notIn): ?><p class="empty">Everyone has checked in or is on leave.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Designation</th><th>Department</th><th>Type</th></tr></thead>
    <tbody>
    <?php foreach ($notIn as $r): ?>
      <tr><td><strong><?= e($r['name']) ?></strong> <span class="small muted"><?= e($r['staff_id']) ?></span></td><td><?= e($r['designation'] ?: '—') ?></td><td><?= e($r['dept'] ?? '—') ?></td><td><?= $r['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$showTeam && $u['track_attendance']): ?>
<div class="card">
  <h2>How it works</h2>
  <p>Sign in while connected to the college network, using your registered device. Your date and time are recorded automatically on your first sign-in of the day. Use <strong>Check out</strong> before you leave.</p>
  <a class="btn btn-light" href="/my_attendance.php">View my attendance</a>
</div>
<?php endif; ?>
<?php render_footer();
