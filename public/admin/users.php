<?php
require __DIR__ . '/../../app/bootstrap.php';
$me = require_perm('manage');

$depts = q('SELECT id, name, code FROM departments ORDER BY name')->fetchAll();
$deptIds = array_map('intval', array_column($depts, 'id'));

function active_sysadmins(): int
{
    return (int)q("SELECT COUNT(*) c FROM users WHERE role = 'sysadmin' AND active = 1")->fetch()['c'];
}

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $data = [
            'staff_id' => mb_substr(post('staff_id'), 0, 30),
            'name'     => mb_substr(post('name'), 0, 120),
            'email'    => mb_strtolower(mb_substr(post('email'), 0, 150)),
            'role'     => post('role'),
            'type'     => post('staff_type'),
            'dept'     => (int)post('department_id'),
            'track'    => isset($_POST['track_attendance']) ? 1 : 0,
            'active'   => isset($_POST['active']) ? 1 : 0,
        ];
        $pw = $_POST['password'] ?? '';
        $err = null;
        if ($data['staff_id'] === '' || $data['name'] === '') {
            $err = 'Staff ID and name are required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $err = 'Enter a valid email address.';
        } elseif (!isset(ROLE_LABELS[$data['role']])) {
            $err = 'Choose a valid role.';
        } elseif (!in_array($data['type'], ['teaching', 'non_teaching'], true)) {
            $err = 'Choose teaching or non-teaching.';
        } elseif ($data['dept'] && !in_array($data['dept'], $deptIds, true)) {
            $err = 'Choose a valid department.';
        } elseif ($data['role'] === 'hod' && !$data['dept']) {
            $err = 'A Head of Department must be linked to a department.';
        } elseif (!$id && (!is_string($pw) || strlen($pw) < 8)) {
            $err = 'Set a temporary password of at least 8 characters.';
        } elseif ($id && $pw !== '' && strlen((string)$pw) < 8) {
            $err = 'The new password must be at least 8 characters.';
        }

        if (!$err && $id) {
            $old = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
            if ($old && $old['role'] === 'sysadmin' && $old['active'] && ($data['role'] !== 'sysadmin' || !$data['active']) && active_sysadmins() <= 1) {
                $err = 'You cannot demote or deactivate the last active System Administrator.';
            }
            if ($id === (int)$me['id'] && !$data['active']) {
                $err = 'You cannot deactivate your own account.';
            }
        }

        if ($err) {
            flash('error', $err);
            redirect('/admin/users.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        try {
            if ($id) {
                q('UPDATE users SET staff_id=?, name=?, email=?, role=?, staff_type=?, department_id=?, track_attendance=?, active=? WHERE id=?',
                    [$data['staff_id'], $data['name'], $data['email'], $data['role'], $data['type'], $data['dept'] ?: null, $data['track'], $data['active'], $id]);
                if ($pw !== '') {
                    q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash((string)$pw, PASSWORD_DEFAULT), $id]);
                }
                audit('user_update', $data['email']);
                flash('ok', 'Saved changes for ' . $data['name'] . '.');
            } else {
                q('INSERT INTO users (staff_id, name, email, password_hash, role, staff_type, department_id, track_attendance, active, must_change_password)
                   VALUES (?,?,?,?,?,?,?,?,?,1)',
                    [$data['staff_id'], $data['name'], $data['email'], password_hash((string)$pw, PASSWORD_DEFAULT), $data['role'], $data['type'], $data['dept'] ?: null, $data['track'], $data['active']]);
                audit('user_create', $data['email']);
                flash('ok', 'Added ' . $data['name'] . '. They must change the temporary password at first sign-in.');
            }
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                flash('error', 'That staff ID or email is already used by another account.');
                redirect('/admin/users.php?' . ($id ? 'edit=' . $id : 'new=1'));
            }
            throw $ex;
        }
        redirect('/admin/users.php');
    }

    if ($action === 'set_role') {
        $role = post('role');
        $t = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
        if (!$t || !isset(ROLE_LABELS[$role])) {
            flash('error', 'Invalid user or role.');
        } elseif ($t['role'] === 'sysadmin' && $role !== 'sysadmin' && $t['active'] && active_sysadmins() <= 1) {
            flash('error', 'You cannot demote the last active System Administrator.');
        } elseif ($role === 'hod' && !$t['department_id']) {
            flash('error', 'Link ' . $t['name'] . ' to a department first (Edit), then make them Head of Department.');
        } else {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            audit('user_role', $t['email'] . ' -> ' . $role);
            flash('ok', $t['name'] . ' is now ' . role_label($role) . '.');
        }
        redirect('/admin/users.php?q=' . urlencode(post('q')));
    }

    if ($action === 'delete') {
        $t = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
        $n = (int)q('SELECT COUNT(*) c FROM attendance WHERE user_id = ?', [$id])->fetch()['c'];
        if (!$t) {
            flash('error', 'User not found.');
        } elseif ($id === (int)$me['id']) {
            flash('error', 'You cannot delete your own account.');
        } elseif ($n > 0 && post('force') !== '1') {
            flash('error', "This person has $n attendance record(s). Deactivate the account to keep history, or use \"Delete with records\" to remove everything.");
        } elseif ($t['role'] === 'sysadmin' && $t['active'] && active_sysadmins() <= 1) {
            flash('error', 'You cannot delete the last active System Administrator.');
        } else {
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('user_delete', $t['email'] . ($n ? " (with $n attendance records)" : ''));
            flash('ok', 'User deleted' . ($n ? " together with $n attendance record(s)." : '.'));
        }
        redirect('/admin/users.php');
    }

    if ($action === 'import') {
        $tmp = (string)post('temp_password');
        $f = $_FILES['file'] ?? null;
        if (strlen($tmp) < 8) {
            flash('error', 'Enter a temporary password of at least 8 characters for the imported staff.');
        } elseif (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1048576) {
            flash('error', 'Choose a CSV file smaller than 1 MB.');
        } else {
            $byCode = [];
            foreach ($depts as $d) {
                $byCode[mb_strtolower($d['code'])] = (int)$d['id'];
            }
            $hash = password_hash($tmp, PASSWORD_DEFAULT);
            $added = $skipped = $updated = 0;
            $update = isset($_POST['update_existing']);
            $problems = [];
            $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f['tmp_name']));
            foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
                if (trim($line) === '') { continue; }
                $c = array_map('trim', str_getcsv($line));
                if ($i === 0 && strtolower($c[0]) === 'staff_id') { continue; }
                [$sid, $name, $email, $type, $code, $role] = array_pad($c, 6, '');
                $type = strtolower(str_replace(['-', ' '], '_', $type));
                $role = $role === '' ? 'staff' : strtolower($role);
                $email = mb_strtolower($email);
                if ($sid === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
                    || !in_array($type, ['teaching', 'non_teaching'], true) || !isset(ROLE_LABELS[$role])) {
                    $problems[] = 'Line ' . ($i + 1);
                    continue;
                }
                $dept = null;
                if ($code !== '') {
                    if (!isset($byCode[mb_strtolower($code)])) {
                        $problems[] = 'Line ' . ($i + 1) . ' (unknown department code "' . $code . '")';
                        continue;
                    }
                    $dept = $byCode[mb_strtolower($code)];
                }
                if ($role === 'hod' && !$dept) {
                    $problems[] = 'Line ' . ($i + 1) . ' (HoD needs a department code)';
                    continue;
                }
                $existing = q('SELECT * FROM users WHERE staff_id = ? OR email = ? LIMIT 1', [$sid, $email])->fetch();
                if ($existing) {
                    if (!$update) { $skipped++; continue; }
                    // Never lock the administrators out of their own system.
                    if ((int)$existing['id'] === (int)$me['id'] && $role !== $existing['role']) {
                        $problems[] = 'Line ' . ($i + 1) . ' (you cannot change your own role)';
                        continue;
                    }
                    if ($existing['role'] === 'sysadmin' && $role !== 'sysadmin' && $existing['active'] && active_sysadmins() <= 1) {
                        $problems[] = 'Line ' . ($i + 1) . ' (last System Administrator)';
                        continue;
                    }
                    try {
                        q('UPDATE users SET staff_id=?, name=?, email=?, role=?, staff_type=?, department_id=? WHERE id=?',
                            [mb_substr($sid, 0, 30), mb_substr($name, 0, 120), mb_substr($email, 0, 150), $role, $type, $dept, $existing['id']]);
                        $updated++;
                    } catch (PDOException $ex) {
                        if ($ex->getCode() !== '23000') { throw $ex; }
                        $problems[] = 'Line ' . ($i + 1) . ' (staff ID or email clashes with another person)';
                    }
                    continue;
                }
                q('INSERT INTO users (staff_id, name, email, password_hash, role, staff_type, department_id, must_change_password) VALUES (?,?,?,?,?,?,?,1)',
                    [mb_substr($sid, 0, 30), mb_substr($name, 0, 120), mb_substr($email, 0, 150), $hash, $role, $type, $dept]);
                $added++;
            }
            audit('user_import', "$added added, $updated updated, $skipped skipped, " . count($problems) . ' problems');
            $msg = "Added $added new staff" . ($update ? ", updated $updated existing" : ", skipped $skipped already existing") . '.';
            if ($problems) { $msg .= ' Not imported: ' . implode('; ', array_slice($problems, 0, 10)) . (count($problems) > 10 ? '; …' : '') . '.'; }
            flash($problems ? 'warn' : 'ok', $msg);
        }
        redirect('/admin/users.php');
    }
}

/* ---------------- display ---------------- */
if (isset($_GET['template'])) {
    csv_download('staff-import-template.csv',
        ['staff_id', 'name', 'email', 'staff_type', 'department_code', 'role'],
        [
            ['CNR001', 'Pema Dorji', 'pdorji.cnr@rub.edu.bt', 'teaching', 'FOR', 'hod'],
            ['CNR002', 'Sonam Choden', 'schoden.cnr@rub.edu.bt', 'non-teaching', 'ADMIN', 'staff'],
            ['CNR003', 'Karma Wangchuk', 'kwangchuk.cnr@rub.edu.bt', 'teaching', 'AGR', 'staff'],
        ]);
}

$editing = null;
if (isset($_GET['new'])) {
    $editing = ['id' => 0, 'staff_id' => '', 'name' => '', 'email' => '', 'role' => 'staff', 'staff_type' => 'teaching',
        'department_id' => null, 'track_attendance' => 1, 'active' => 1];
} elseif (isset($_GET['edit'])) {
    $editing = q('SELECT * FROM users WHERE id = ?', [(int)$_GET['edit']])->fetch() ?: null;
}

render_header($editing ? ($editing['id'] ? 'Edit user' : 'Add user') : 'Staff & Users', 'a_users');

if ($editing): ?>
<div class="card" style="max-width:820px">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
    <div class="form-grid">
      <div class="field"><label>Staff ID</label><input type="text" name="staff_id" maxlength="30" required value="<?= e($editing['staff_id']) ?>"></div>
      <div class="field"><label>Full name</label><input type="text" name="name" maxlength="120" required value="<?= e($editing['name']) ?>"></div>
      <div class="field"><label>Email</label><input type="email" name="email" maxlength="150" required value="<?= e($editing['email']) ?>"></div>
      <div class="field"><label>Role</label>
        <select name="role"><?php foreach (ROLE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $editing['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Staff type</label>
        <select name="staff_type">
          <option value="teaching" <?= $editing['staff_type'] === 'teaching' ? 'selected' : '' ?>>Teaching (8:00 – 5:30)</option>
          <option value="non_teaching" <?= $editing['staff_type'] === 'non_teaching' ? 'selected' : '' ?>>Non-teaching (8:30 – 4:30)</option></select></div>
      <div class="field"><label>Department</label>
        <select name="department_id"><option value="0">— none —</option>
          <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$editing['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
        <div class="hint">Required for Head of Department.</div></div>
      <div class="field"><label><?= $editing['id'] ? 'New password' : 'Temporary password' ?></label>
        <input type="password" name="password" minlength="8" autocomplete="new-password" <?= $editing['id'] ? '' : 'required' ?>>
        <div class="hint"><?= $editing['id'] ? 'Leave blank to keep the current password. A new one must be changed at next sign-in.' : 'Staff must change it at first sign-in.' ?></div></div>
    </div>
    <div class="field"><label class="check"><input type="checkbox" name="track_attendance" <?= $editing['track_attendance'] ? 'checked' : '' ?>> Track this person's attendance</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="active" <?= $editing['active'] ? 'checked' : '' ?>> Account active</label></div>
    <div class="actions"><button class="btn" type="submit">Save</button><a class="btn btn-light" href="/admin/users.php">Cancel</a></div>
  </form>
</div>
<?php else:
    $search = trim($_GET['q'] ?? '');
    $sql = 'SELECT u.*, d.name AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE 1=1';
    $p = [];
    if ($search !== '') {
        $sql .= ' AND (u.name LIKE ? OR u.staff_id LIKE ? OR u.email LIKE ?)';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        array_push($p, $like, $like, $like);
    }
    $sql .= ' ORDER BY u.active DESC, u.name';
    $users = q($sql, $p)->fetchAll();
?>
<div class="card">
  <div class="card-head"><h2>Staff & users (<?= count($users) ?>)</h2><a class="btn" href="?new=1">+ Add user</a></div>
  <form method="get" class="filters">
    <div class="field" style="min-width:260px"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, staff ID or email"></div>
    <button class="btn btn-light" type="submit">Search</button>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Role</th><th>Department</th><th>Type</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$users): ?><tr><td colspan="6" class="empty">No users found.</td></tr><?php endif; ?>
    <?php foreach ($users as $x): ?>
      <tr>
        <td><strong><?= e($x['name']) ?></strong><div class="small muted"><?= e($x['staff_id']) ?> · <?= e($x['email']) ?></div></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="q" value="<?= e($search) ?>">
            <select name="role" data-autosubmit aria-label="Role for <?= e($x['name']) ?>" style="padding:5px 8px;font-size:13px">
              <?php foreach (ROLE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $x['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </form>
        </td>
        <td><?= e($x['dept'] ?? '—') ?></td>
        <td><?= $x['staff_type'] === 'teaching' ? 'Teaching' : 'Non-teaching' ?><?= $x['track_attendance'] ? '' : ' <span class="badge badge-muted">not tracked</span>' ?></td>
        <td><?= $x['active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
        <td class="right"><div class="actions" style="justify-content:flex-end">
          <a class="btn btn-light btn-sm" href="?edit=<?= (int)$x['id'] ?>">Edit</a>
          <form method="post" class="inline" data-confirm="Delete <?= e($x['name']) ?>? This cannot be undone.">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
          <form method="post" class="inline" data-confirm="PERMANENTLY delete <?= e($x['name']) ?> AND all of their attendance records and devices? This cannot be undone.">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="force" value="1"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <button class="btn btn-light btn-sm" type="submit" title="Also removes attendance records">Delete with records</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card" style="max-width:820px">
  <h2>Import many staff from CSV</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <div class="form-grid">
      <div class="field"><label for="file">CSV file</label><input type="file" id="file" name="file" accept=".csv,.txt" required></div>
      <div class="field"><label for="temp_password">Temporary password for everyone</label><input type="password" id="temp_password" name="temp_password" minlength="8" required autocomplete="new-password"></div>
    </div>
    <div class="field"><label class="check"><input type="checkbox" name="update_existing"> Also update people who already exist (matched by staff ID or email) — use this to change roles / departments in bulk. Passwords of existing staff are never changed.</label></div>
    <div class="actions"><button class="btn" type="submit">Import</button><a class="btn btn-light" href="?template=1">Download CSV template</a></div>
  </form>
  <p class="hint" style="margin-top:12px">Columns (no header needed): <code>staff_id, name, email, staff_type, department_code, role</code><br>
    <code>staff_type</code> = <code>teaching</code> or <code>non-teaching</code>. <code>department_code</code> must match a code on the Departments page. <code>role</code> is optional (default <code>staff</code>): <?= e(implode(', ', array_keys(ROLE_LABELS))) ?>.<br>
    Existing staff IDs / emails are skipped unless you tick the update box. New staff must change the temporary password at first sign-in. Rows with an unknown department code are reported and not imported.</p>
</div>
<?php endif;
render_footer();
