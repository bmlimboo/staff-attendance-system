<?php
require __DIR__ . '/../../app/bootstrap.php';
require_perm('manage');

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

if (is_post()) {
    csrf_verify();
    $err = null;

    $times = [];
    foreach (['teaching_start', 'teaching_end', 'nonteaching_start', 'nonteaching_end', 'lunch_start', 'lunch_end'] as $k) {
        $v = post($k);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            $err = 'All times must be valid (HH:MM).';
            break;
        }
        $times[$k] = $v;
    }

    $nets = preg_split('/[\s,;]+/', post('allowed_networks'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($nets as $n) {
        if (!valid_cidr($n)) {
            $err = "\"$n\" is not a valid IP address or network (use a format like 192.168.10.0/24).";
            break;
        }
    }
    if (!$err && !$nets) {
        $err = 'Add at least one office network, otherwise nobody can record attendance.';
    }

    $grace = (int)post('grace_minutes');
    $maxDev = (int)post('max_devices');
    $days = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['working_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
    if (!$err && ($grace < 0 || $grace > 120)) { $err = 'Grace period must be between 0 and 120 minutes.'; }
    if (!$err && ($maxDev < 1 || $maxDev > 10)) { $err = 'Devices per staff must be between 1 and 10.'; }
    if (!$err && !$days) { $err = 'Select at least one working day.'; }

    if ($err) {
        flash('error', $err);
    } else {
        foreach ($times as $k => $v) { save_setting($k, $v); }
        save_setting('allowed_networks', implode("\n", $nets));
        save_setting('grace_minutes', (string)$grace);
        save_setting('max_devices', (string)$maxDev);
        sort($days);
        save_setting('working_days', implode(',', $days));
        audit('settings_update', 'networks: ' . implode(' ', $nets));
        flash('ok', 'Settings saved.');
    }
    redirect('/admin/settings.php');
}

$s = settings(true);
$workDays = working_day_numbers();

render_header('Settings', 'a_set');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="card">
      <h2>Office network</h2>
      <div class="field">
        <label for="allowed_networks">Allowed networks</label>
        <textarea id="allowed_networks" name="allowed_networks" required><?= e($s['allowed_networks'] ?? '') ?></textarea>
        <div class="hint">One per line, in CIDR form (e.g. <code>192.168.10.0/24</code>) or a single IP. Staff can only sign in and record attendance from these networks. Your current IP is <strong><?= e(client_ip()) ?></strong> — <?= on_office_network() ? 'inside' : 'outside' ?> the list.</div>
      </div>
      <div class="field"><label for="max_devices">Devices allowed per staff</label><input type="number" id="max_devices" name="max_devices" min="1" max="10" value="<?= e($s['max_devices'] ?? '2') ?>"></div>
    </div>

    <div class="card">
      <h2>Working days</h2>
      <?php foreach ($dayNames as $n => $l): ?>
        <div class="field" style="margin-bottom:6px"><label class="check"><input type="checkbox" name="working_days[]" value="<?= $n ?>" <?= in_array($n, $workDays, true) ? 'checked' : '' ?>> <?= $l ?></label></div>
      <?php endforeach; ?>
      <div class="field" style="margin-top:14px"><label for="grace_minutes">Grace period (minutes)</label>
        <input type="number" id="grace_minutes" name="grace_minutes" min="0" max="120" value="<?= e($s['grace_minutes'] ?? '0') ?>">
        <div class="hint">Arriving within this many minutes after the start time is still "on time". Use 0 for strict timing.</div></div>
    </div>

    <div class="card">
      <h2>Teaching staff hours</h2>
      <div class="form-grid">
        <div class="field"><label>Start</label><input type="time" name="teaching_start" required value="<?= e($s['teaching_start'] ?? '08:00') ?>"></div>
        <div class="field"><label>End</label><input type="time" name="teaching_end" required value="<?= e($s['teaching_end'] ?? '17:30') ?>"></div>
      </div>
    </div>
    <div class="card">
      <h2>Non-teaching staff hours</h2>
      <div class="form-grid">
        <div class="field"><label>Start</label><input type="time" name="nonteaching_start" required value="<?= e($s['nonteaching_start'] ?? '08:30') ?>"></div>
        <div class="field"><label>End</label><input type="time" name="nonteaching_end" required value="<?= e($s['nonteaching_end'] ?? '16:30') ?>"></div>
      </div>
    </div>
    <div class="card">
      <h2>Lunch break</h2>
      <div class="form-grid">
        <div class="field"><label>From</label><input type="time" name="lunch_start" required value="<?= e($s['lunch_start'] ?? '12:00') ?>"></div>
        <div class="field"><label>To</label><input type="time" name="lunch_end" required value="<?= e($s['lunch_end'] ?? '13:00') ?>"></div>
      </div>
      <div class="hint">Lunch time is not counted in hours worked.</div>
    </div>
  </div>
  <button class="btn" type="submit">Save settings</button>
</form>
<?php render_footer();
