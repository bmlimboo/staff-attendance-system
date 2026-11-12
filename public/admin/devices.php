<?php
require __DIR__ . '/../../app/bootstrap.php';
$me = require_perm('manage');

function short_ua(string $ua): string
{
    $os = 'Unknown OS';
    foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Mac OS X' => 'macOS', 'Linux' => 'Linux', 'CrOS' => 'ChromeOS'] as $k => $v) {
        if (stripos($ua, $k) !== false) { $os = $v; break; }
    }
    $br = 'Browser';
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $k => $v) {
        if (stripos($ua, $k) !== false) { $br = $v; break; }
    }
    return "$br on $os";
}

if (is_post()) {
    csrf_verify();
    $id = (int)post('id');
    $action = post('action');
    $d = q('SELECT d.*, u.name FROM devices d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id])->fetch();
    if (!$d) {
        flash('error', 'Device not found.');
    } elseif ($action === 'delete') {
        q('DELETE FROM devices WHERE id = ?', [$id]);
        audit('device_delete', $d['name'] . ' / ' . $d['label']);
        flash('ok', 'Device removed. The staff member can register a new one.');
    } else {
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'];
        if (isset($map[$action])) {
            q('UPDATE devices SET status = ?, decided_at = ?, decided_by = ? WHERE id = ?',
                [$map[$action], date('Y-m-d H:i:s'), $me['id'], $id]);
            audit('device_' . $action, $d['name'] . ' / ' . $d['label']);
            flash('ok', ucfirst($map[$action]) . ': ' . $d['label'] . ' (' . $d['name'] . ').');
        }
    }
    redirect('/admin/devices.php?status=' . urlencode(post('status', 'pending')));
}

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'approved', 'rejected', 'revoked', 'all'], true)) { $status = 'pending'; }

$sql = 'SELECT d.*, u.name, u.staff_id, dep.name AS dept FROM devices d JOIN users u ON u.id = d.user_id
        LEFT JOIN departments dep ON dep.id = u.department_id';
$p = [];
if ($status !== 'all') { $sql .= ' WHERE d.status = ?'; $p[] = $status; }
$sql .= ' ORDER BY d.requested_at DESC';
$rows = q($sql, $p)->fetchAll();

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'revoked' => 0];
foreach (q('SELECT status, COUNT(*) c FROM devices GROUP BY status') as $r) { $counts[$r['status']] = (int)$r['c']; }

render_header('Device Approvals', 'a_dev');
?>
<div class="card">
  <div class="card-head">
    <h2>Registered devices</h2>
    <div class="actions">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'revoked' => 'Revoked'] as $k => $l): ?>
        <a class="btn <?= $status === $k ? '' : 'btn-light' ?> btn-sm" href="?status=<?= $k ?>"><?= $l ?> (<?= $counts[$k] ?>)</a>
      <?php endforeach; ?>
      <a class="btn <?= $status === 'all' ? '' : 'btn-light' ?> btn-sm" href="?status=all">All</a>
    </div>
  </div>
  <p class="small muted">Check the device details before approving. A laptop or phone is approved once; if someone changes device, remove the old one so they can register again.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Device</th><th>Browser details</th><th>Requested</th><th>Last used</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing here.</td></tr><?php endif; ?>
    <?php foreach ($rows as $d): $cls = ['approved' => 'ok', 'pending' => 'info', 'rejected' => 'bad', 'revoked' => 'bad'][$d['status']]; ?>
      <tr>
        <td><strong><?= e($d['name']) ?></strong><div class="small muted"><?= e($d['staff_id']) ?> · <?= e($d['dept'] ?? 'No department') ?></div></td>
        <td><?= e($d['label']) ?><div class="small muted"><?= $d['device_type'] === 'laptop' ? 'Laptop' : 'Mobile' ?></div></td>
        <td class="small"><?= e(short_ua($d['user_agent'])) ?><div class="muted"><?= e($d['platform']) ?><?= $d['screen'] ? ' · ' . e($d['screen']) : '' ?></div></td>
        <td class="small nowrap"><?= e(date('d M Y, g:i A', strtotime($d['requested_at']))) ?></td>
        <td class="small nowrap"><?= $d['last_used_at'] ? e(date('d M, g:i A', strtotime($d['last_used_at']))) : '—' ?></td>
        <td><span class="badge badge-<?= $cls ?>"><?= e(ucfirst($d['status'])) ?></span></td>
        <td class="right"><div class="actions" style="justify-content:flex-end">
          <?php foreach (['pending' => [['approve', 'Approve', ''], ['reject', 'Reject', 'btn-light']],
                          'approved' => [['revoke', 'Revoke', 'btn-light']],
                          'rejected' => [['approve', 'Approve', '']],
                          'revoked' => [['approve', 'Re-approve', '']]][$d['status']] as [$act, $lbl, $cl]): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
              <button class="btn btn-sm <?= $cl ?>" type="submit"><?= $lbl ?></button></form>
          <?php endforeach; ?>
          <form method="post" class="inline" data-confirm="Remove this device record?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="status" value="<?= e($status) ?>">
            <button class="btn btn-danger btn-sm" type="submit">Remove</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
