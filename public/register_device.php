<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

if (!$u['track_attendance']) {
    redirect('/dashboard.php');
}

$onNet = on_office_network();
$error = '';
$ds    = device_state($u);
$max   = (int)setting('max_devices', '2');

function looks_mobile(string $ua): bool
{
    return (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua);
}

if (is_post()) {
    csrf_verify();
    $label = post('label');
    $type  = post('device_type');
    $ua    = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    if (!$onNet) {
        $error = 'Register your device while connected to the college network.';
    } elseif ($ds['state'] !== 'none') {
        $error = 'This browser is already registered (status: ' . $ds['state'] . ').';
    } elseif ($label === '' || mb_strlen($label) > 80) {
        $error = 'Give the device a short name, for example "My Dell laptop" or "Pema iPhone".';
    } elseif (!in_array($type, ['laptop', 'mobile'], true)) {
        $error = 'Choose whether this is a laptop or a mobile phone.';
    } elseif ($type === 'mobile' && !looks_mobile($ua)) {
        $error = 'This browser does not look like a phone or tablet. Pick "Laptop" if this is your laptop.';
    } elseif ($ds['active_count'] >= $max) {
        $error = "You already have $max registered devices. Ask the system administrator to remove one first.";
    } else {
        $token = device_token();
        // A browser that is already registered to someone else cannot be reused.
        $taken = $token ? q("SELECT id FROM devices WHERE token_hash = ? AND status IN ('pending','approved')",
            [hash('sha256', $token)])->fetch() : false;
        if ($taken) {
            $error = 'This browser is already registered to another staff member. Ask the administrator.';
        } else {
            $token = bin2hex(random_bytes(32));
            q('INSERT INTO devices (user_id, token_hash, label, device_type, user_agent, platform, screen, status, requested_at)
               VALUES (?,?,?,?,?,?,?,?,?)',
                [$u['id'], hash('sha256', $token), $label, $type, $ua,
                 mb_substr(post('platform'), 0, 60), mb_substr(post('screen'), 0, 20), 'pending', date('Y-m-d H:i:s')]);
            set_device_cookie($token);
            audit('device_request', $label . ' (' . $type . ')');
            flash('ok', 'Device submitted. The system administrator will approve it, after which signing in records your attendance.');
            redirect('/register_device.php');
        }
    }
}

$mine = q('SELECT * FROM devices WHERE user_id = ? ORDER BY requested_at DESC', [$u['id']])->fetchAll();
$ds   = device_state($u);

render_header('My Devices', 'devices');
?>
<?php if (!$onNet): ?>
  <div class="alert alert-warn">You are outside the college network. Connect to the college Wi-Fi or LAN to register a device.</div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Register this device</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($ds['state'] === 'approved'): ?>
      <div class="alert alert-ok">This browser is <strong>approved</strong> — your attendance is recorded when you sign in.</div>
    <?php elseif ($ds['state'] === 'pending'): ?>
      <div class="alert alert-info">This browser is <strong>waiting for approval</strong> by the system administrator.</div>
    <?php elseif (in_array($ds['state'], ['rejected', 'revoked'], true)): ?>
      <div class="alert alert-error">This browser was <strong><?= e($ds['state']) ?></strong>. Please contact the system administrator.</div>
    <?php else: ?>
      <p class="muted">Register the laptop or phone you normally use. The administrator must approve it once before it can record attendance. You can have up to <?= (int)$max ?> devices.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="screen" id="dev_screen">
        <input type="hidden" name="platform" id="dev_platform">
        <div class="field">
          <label for="label">Device name</label>
          <input type="text" id="label" name="label" maxlength="80" placeholder="e.g. My HP laptop" required>
        </div>
        <div class="field">
          <label for="device_type">Device type</label>
          <select id="device_type" name="device_type" required>
            <option value="">Choose…</option>
            <option value="laptop">Laptop</option>
            <option value="mobile">Mobile phone / tablet</option>
          </select>
          <div class="hint">Office desktop computers are not accepted.</div>
        </div>
        <button class="btn" type="submit" <?= $onNet ? '' : 'disabled' ?>>Submit for approval</button>
      </form>
      <p class="hint" style="margin-top:14px">Tip: register using the browser you will always use. Clearing browser data or using a private window will require a new approval.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>My registered devices</h2>
    <?php if (!$mine): ?>
      <p class="muted">No devices yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Device</th><th>Type</th><th>Status</th><th>Last used</th></tr></thead>
      <tbody>
      <?php foreach ($mine as $d): ?>
        <?php $cls = ['approved' => 'ok', 'pending' => 'info', 'rejected' => 'bad', 'revoked' => 'bad'][$d['status']]; ?>
        <tr>
          <td><?= e($d['label']) ?><?= ($ds['device'] && $ds['device']['id'] === $d['id']) ? ' <span class="badge badge-muted">this browser</span>' : '' ?></td>
          <td><?= e(ucfirst($d['device_type'])) ?></td>
          <td><span class="badge badge-<?= $cls ?>"><?= e(ucfirst($d['status'])) ?></span></td>
          <td class="small muted"><?= $d['last_used_at'] ? e(date('d M, g:i A', strtotime($d['last_used_at']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();
