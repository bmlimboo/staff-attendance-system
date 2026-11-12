<?php
require __DIR__ . '/../../app/bootstrap.php';
require_perm('manage');

$rows = q('SELECT l.*, u.name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 300')->fetchAll();

render_header('Audit Log', 'a_audit');
?>
<div class="card">
  <h2>Recent activity</h2>
  <p class="small muted">The last 300 actions: sign-ins, edits to attendance, device approvals and setting changes.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Nothing logged yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap small"><?= e(date('d M Y, g:i:s A', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['name'] ?? '—') ?></td>
        <td><span class="badge badge-muted"><?= e($r['action']) ?></span></td>
        <td class="small"><?= e($r['details']) ?></td>
        <td class="small muted"><?= e($r['ip_address']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
