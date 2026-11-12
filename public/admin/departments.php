<?php
require __DIR__ . '/../../app/bootstrap.php';
require_perm('manage');

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');
    $name = mb_substr(post('name'), 0, 120);
    $code = strtoupper(mb_substr(post('code'), 0, 20));

    try {
        if ($action === 'add' || $action === 'rename') {
            if ($name === '' || $code === '' || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
                flash('error', 'Enter a name and a short code (letters, numbers, - or _), e.g. FOR.');
            } elseif ($action === 'add') {
                q('INSERT INTO departments (name, code) VALUES (?,?)', [$name, $code]);
                audit('dept_add', "$name ($code)");
                flash('ok', 'Department added.');
            } else {
                q('UPDATE departments SET name = ?, code = ? WHERE id = ?', [$name, $code, $id]);
                audit('dept_update', "$name ($code)");
                flash('ok', 'Department updated.');
            }
        } elseif ($action === 'delete') {
            $n = (int)q('SELECT COUNT(*) c FROM users WHERE department_id = ?', [$id])->fetch()['c'];
            if ($n > 0) {
                flash('error', "$n staff belong to this department. Move them to another department first.");
            } else {
                q('DELETE FROM departments WHERE id = ?', [$id]);
                audit('dept_delete', 'id ' . $id);
                flash('ok', 'Department deleted.');
            }
        }
    } catch (PDOException $ex) {
        if ($ex->getCode() !== '23000') { throw $ex; }
        flash('error', 'A department with that name or code already exists.');
    }
    redirect('/admin/departments.php');
}

$rows = q('SELECT d.*, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS n FROM departments d ORDER BY d.name')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($rows as $r) { if ((int)$r['id'] === (int)$_GET['edit']) { $edit = $r; } }
}

render_header('Departments', 'a_dept');
?>
<div class="grid grid-2" style="grid-template-columns:1.5fr 1fr">
  <div class="card">
    <h2>Departments</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Code</th><th>Staff</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="empty">No departments yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong></td><td><code><?= e($r['code']) ?></code></td><td><?= (int)$r['n'] ?></td>
          <td class="right"><div class="actions" style="justify-content:flex-end">
            <a class="btn btn-light btn-sm" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" class="inline" data-confirm="Delete this department?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h2><?= $edit ? 'Edit department' : 'Add department' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'rename' : 'add' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" maxlength="120" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="field"><label for="code">Short code</label><input type="text" id="code" name="code" maxlength="20" required value="<?= e($edit['code'] ?? '') ?>"><div class="hint">Used when importing staff from CSV.</div></div>
      <div class="actions"><button class="btn" type="submit"><?= $edit ? 'Save' : 'Add' ?></button><?php if ($edit): ?><a class="btn btn-light" href="/admin/departments.php">Cancel</a><?php endif; ?></div>
    </form>
  </div>
</div>
<?php render_footer();
