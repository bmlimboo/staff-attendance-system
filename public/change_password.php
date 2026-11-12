<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$forced = (bool)$u['must_change_password'];
$error = '';

if (is_post()) {
    csrf_verify();
    $cur = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $cnf = $_POST['confirm'] ?? '';
    if (!password_verify((string)$cur, $u['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif (strlen((string)$new) < 8) {
        $error = 'The new password must be at least 8 characters.';
    } elseif ($new !== $cnf) {
        $error = 'The new passwords do not match.';
    } elseif ($new === $cur) {
        $error = 'Choose a password different from the current one.';
    } else {
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash((string)$new, PASSWORD_DEFAULT), $u['id']]);
        audit('password_change');
        session_regenerate_id(true);
        flash('ok', 'Password updated.');
        redirect('/dashboard.php');
    }
}

render_header('Change password');
?>
<div class="card" style="max-width:520px">
  <?php if ($forced): ?><div class="alert alert-warn">For security, please choose a new password before continuing.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
    <div class="field"><label>New password</label><input type="password" name="new" required minlength="8" autocomplete="new-password"><div class="hint">At least 8 characters.</div></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></div>
    <button class="btn" type="submit">Update password</button>
  </form>
</div>
<?php render_footer();
