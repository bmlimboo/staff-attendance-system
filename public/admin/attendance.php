<?php
require __DIR__ . '/../../app/bootstrap.php';
$me = require_perm('manage');

$date = $_GET['date'] ?? date('Y-m-d');
if (!valid_date($date)) { $date = date('Y-m-d'); }
$deptId = (int)($_GET['dept'] ?? 0);

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $uid    = (int)post('user_id');
    $d      = post('date');
    $back   = '/admin/attendance.php?date=' . urlencode($d) . '&dept=' . (int)post('dept');
    $user   = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();

    if (!$user || !valid_date($d)) {
        flash('error', 'Invalid staff member or date.');
        redirect($back);
    }

    if ($action === 'delete') {
        q('DELETE FROM attendance WHERE user_id = ? AND att_date = ?', [$uid, $d]);
        audit('attendance_delete', $user['name'] . ' ' . $d);
        flash('ok', 'Record deleted for ' . $user['name'] . ' on ' . fmt_date($d) . '.');
        redirect($back);
    }

    if ($action === 'save') {
        $in  = post('check_in');
        $out = post('check_out');
        $st  = post('status', 'auto');
        $rem = mb_substr(post('remarks'), 0, 255);

        $in  = $in  !== '' ? (strlen($in)  === 5 ? $in  . ':00' : $in)  : null;
        $out = $out !== '' ? (strlen($out) === 5 ? $out . ':00' : $out) : null;

        $err = null;
        if (($in && !valid_time($in)) || ($out && !valid_time($out))) {
            $err = 'Enter valid times.';
        } elseif ($in && $out && $out < $in) {
            $err = 'Check-out cannot be earlier than check-in.';
        } elseif (!in_array($st, ['auto', 'present', 'late', 'leave', 'absent'], true)) {
            $err = 'Invalid status.';
        } elseif ($st === 'auto' && !$in) {
            $err = 'Enter a check-in time, or choose a status such as Leave or Absent.';
        }
        if ($err) {
            flash('error', $err);
            redirect('/admin/attendance.php?date=' . urlencode($d) . '&dept=' . (int)post('dept') . '&edit=' . $uid);
        }

        $late = 0;
        if ($in) {
            $late = late_minutes($in, $user['staff_type'], $uid, $d);
        }
        if ($st === 'auto') {
            $st = $late > 0 ? 'late' : 'present';
        }
        if ($st !== 'late') {
            $late = 0;
        } elseif ($late === 0 && $in) {
            // admin forced "late" - keep minutes after the official start time
            [$start] = schedule_for($user['staff_type']);
            $late = max(0, tmin($in) - tmin($start));
        }

        q('INSERT INTO attendance (user_id, att_date, check_in, check_out, status, late_minutes, ip_address, source, remarks, updated_by)
           VALUES (?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out), status = VALUES(status),
             late_minutes = VALUES(late_minutes), source = VALUES(source), remarks = VALUES(remarks), updated_by = VALUES(updated_by)',
            [$uid, $d, $in, $out, $st, $late, '', 'admin', $rem, $me['id']]);
        audit('attendance_save', $user['name'] . " $d in=" . ($in ?? '-') . ' out=' . ($out ?? '-') . " $st");
        flash('ok', 'Saved attendance for ' . $user['name'] . ' on ' . fmt_date($d) . '.');
        redirect($back);
    }
}

/* ---------- display ---------- */
$sql = 'SELECT u.id, u.name, u.staff_id, u.staff_type, d.name AS dept, a.check_in, a.check_out, a.status, a.late_minutes, a.source, a.remarks, a.id AS aid
        FROM users u LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN attendance a ON a.user_id = u.id AND a.att_date = ?
        WHERE u.active = 1 AND u.track_attendance = 1';
$p = [$date];
if ($deptId) { $sql .= ' AND u.department_id = ?'; $p[] = $deptId; }
$sql .= ' ORDER BY u.name';
$rows = q($sql, $p)->fetchAll();
$depts = q('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$holiday = holiday_title($date);

$edit = null;
if (isset($_GET['edit'])) {
    foreach ($rows as $r) {
        if ((int)$r['id'] === (int)$_GET['edit']) { $edit = $r; break; }
    }
    if (!$edit) { // staff filtered out by department - load directly
        $edit = q('SELECT u.id, u.name, u.staff_id, u.staff_type, NULL AS dept, a.check_in, a.check_out, a.status, a.late_minutes, a.source, a.remarks, a.id AS aid
                   FROM users u LEFT JOIN attendance a ON a.user_id = u.id AND a.att_date = ? WHERE u.id = ?', [$date, (int)$_GET['edit']])->fetch() ?: null;
    }
}

render_header('Edit Attendance', 'a_att');
?>
<?php if ($edit): ?>
<div class="card" style="max-width:720px">
  <h2><?= $edit['aid'] ? 'Edit' : 'Add' ?> attendance — <?= e($edit['name']) ?></h2>
  <p class="muted"><?= e(fmt_date($date)) ?><?= $holiday ? ' · Holiday: ' . e($holiday) : '' ?> · <?= $edit['staff_type'] === 'teaching' ? 'Teaching staff' : 'Non-teaching staff' ?> (official start <?= e(fmt_time(schedule_for($edit['staff_type'])[0])) ?>)</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="user_id" value="<?= (int)$edit['id'] ?>">
    <input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="dept" value="<?= $deptId ?>">
    <div class="form-grid">
      <div class="field"><label for="check_in">Check-in time</label><input type="time" id="check_in" name="check_in" value="<?= e(substr((string)$edit['check_in'], 0, 5)) ?>"></div>
      <div class="field"><label for="check_out">Check-out time</label><input type="time" id="check_out" name="check_out" value="<?= e(substr((string)$edit['check_out'], 0, 5)) ?>"></div>
      <div class="field"><label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (['auto' => 'Automatic (from check-in time)', 'present' => 'On time', 'late' => 'Late', 'leave' => 'On leave', 'absent' => 'Absent'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= ($edit['aid'] ? $edit['status'] : 'auto') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="remarks">Remarks</label><input type="text" id="remarks" name="remarks" maxlength="255" value="<?= e($edit['remarks'] ?? '') ?>" placeholder="e.g. Network down, approved by Registrar"></div>
    <div class="actions">
      <button class="btn" type="submit">Save</button>
      <a class="btn btn-light" href="?date=<?= e($date) ?>&dept=<?= $deptId ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2>Attendance on <?= e(fmt_date($date)) ?></h2></div>
  <?php if ($holiday): ?><div class="alert alert-info">This date is a holiday: <?= e($holiday) ?>.</div><?php endif; ?>
  <form method="get" class="filters">
    <div class="field"><label for="date">Date</label><input type="date" id="date" name="date" value="<?= e($date) ?>" data-autosubmit></div>
    <div class="field"><label for="dept">Department</label>
      <select id="dept" name="dept" data-autosubmit><option value="0">All</option>
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-light" type="submit">Show</button>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Department</th><th>Check-in</th><th>Check-out</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No staff found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong> <span class="small muted"><?= e($r['staff_id']) ?></span></td>
        <td><?= e($r['dept'] ?? '—') ?></td>
        <td><?= e(fmt_time($r['check_in'])) ?></td>
        <td><?= e(fmt_time($r['check_out'])) ?></td>
        <td><?php if ($r['aid']): ?><?= status_badge($r['status']) ?><?= $r['status'] === 'late' ? ' <span class="small muted">' . (int)$r['late_minutes'] . ' min</span>' : '' ?>
              <?= $r['source'] === 'admin' ? '<span class="badge badge-muted">edited</span>' : '' ?>
            <?php else: ?><span class="badge badge-muted">No record</span><?php endif; ?></td>
        <td class="right"><div class="actions" style="justify-content:flex-end">
          <a class="btn btn-light btn-sm" href="?date=<?= e($date) ?>&dept=<?= $deptId ?>&edit=<?= (int)$r['id'] ?>"><?= $r['aid'] ? 'Edit' : 'Add' ?></a>
          <?php if ($r['aid']): ?>
          <form method="post" class="inline" data-confirm="Delete this attendance record?">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="dept" value="<?= $deptId ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
