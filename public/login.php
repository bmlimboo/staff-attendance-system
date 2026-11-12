<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$error  = '';
$onNet  = on_office_network();
$ident  = '';

if (is_post()) {
    csrf_verify();
    $ident = post('identifier');
    $pw    = $_POST['password'] ?? '';
    $pw    = is_string($pw) ? $pw : '';

    if ($ident === '' || $pw === '') {
        $error = 'Please enter your email / staff ID and password.';
    } elseif (too_many_attempts($ident)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $u = q('SELECT * FROM users WHERE (email = ? OR staff_id = ?) AND active = 1 LIMIT 1', [$ident, $ident])->fetch();
        $hash = $u ? $u['password_hash'] : password_hash('not-a-real-user', PASSWORD_DEFAULT);
        $ok = password_verify($pw, $hash) && $u;

        if (!$ok) {
            record_failed_login($ident);
            $error = 'Invalid email / staff ID or password.';
        } elseif ($u['role'] === 'staff' && !$onNet) {
            $error = 'You must be connected to the college network to sign in.';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            clear_failed_logins($ident);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
            }
            audit('login', $u['email']);
            $_SESSION['checkin_result'] = attempt_checkin($u);
            redirect('/dashboard.php');
        }
    }
}

render_header('Sign in');
?>
<div class="login-wrap">
  <section class="login-art">
    <div class="brand-mark">CNR</div>
    <h1>Staff Attendance</h1>
    <p>College of Natural Resources, Royal University of Bhutan. Sign in from the college network and your attendance is recorded automatically.</p>
    <ul>
      <li><?= icon('wifi') ?> Works on the college network</li>
      <li><?= icon('device') ?> Registered laptop or phone only</li>
      <li><?= icon('clock') ?> Date and time recorded for you</li>
    </ul>
  </section>
  <section class="login-form">
    <div class="login-box">
      <h2>Welcome back</h2>
      <p class="muted">Use your college email or staff ID.</p>
      <div class="net">
        <span class="net-chip <?= $onNet ? 'on' : 'off' ?>">
          <?= icon('wifi') ?><?= $onNet ? 'You are on the college network' : 'You are outside the college network' ?>
        </span>
        <div class="hint">Your IP address: <?= e(client_ip()) ?></div>
      </div>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
          <label for="identifier">Email or Staff ID</label>
          <input type="text" id="identifier" name="identifier" value="<?= e($ident) ?>" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn" type="submit" style="width:100%;justify-content:center">Sign in</button>
      </form>
    </div>
  </section>
</div>
<?php render_footer();
