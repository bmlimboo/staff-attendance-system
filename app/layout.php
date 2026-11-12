<?php
declare(strict_types=1);

function nav_items(): array
{
    $u = current_user();
    $items = [];
    $items[] = ['/dashboard.php', 'Dashboard', 'home', 'dashboard'];
    if ($u && $u['track_attendance']) {
        $items[] = ['/my_attendance.php', 'My Attendance', 'clock', 'my'];
        $items[] = ['/register_device.php', 'My Devices', 'device', 'devices'];
    }
    if (can('view_late_all') || can('view_late_dept')) {
        $items[] = ['/late.php', 'Late Comers', 'alert', 'late'];
    }
    if (can('view_all')) {
        $items[] = ['/reports.php', 'Attendance Reports', 'chart', 'reports'];
    }
    $items[] = ['/holidays.php', 'College Calendar', 'calendar', 'holidays'];

    if (can('manage')) {
        $pending = (int)q("SELECT COUNT(*) c FROM devices WHERE status = 'pending'")->fetch()['c'];
        $items[] = ['section', 'Administration'];
        $items[] = ['/admin/attendance.php', 'Edit Attendance', 'edit', 'a_att'];
        $items[] = ['/admin/devices.php', 'Device Approvals', 'device', 'a_dev', $pending];
        $items[] = ['/admin/users.php', 'Staff & Users', 'users', 'a_users'];
        $items[] = ['/admin/departments.php', 'Departments', 'building', 'a_dept'];
        $items[] = ['/admin/settings.php', 'Settings', 'settings', 'a_set'];
        $items[] = ['/admin/audit.php', 'Audit Log', 'log', 'a_audit'];
    }
    return $items;
}

function render_header(string $title, string $active = ''): void
{
    $u = current_user();
    $app = cfg('app_name', 'Staff Attendance');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<script src="/assets/app.js" defer></script>
</head>
<body>
<?php if ($u): ?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">CNR</div>
      <div class="brand-text"><strong>Staff Attendance</strong><span>College of Natural Resources</span></div>
    </div>
    <nav>
      <?php foreach (nav_items() as $it): ?>
        <?php if ($it[0] === 'section'): ?>
          <div class="nav-section"><?= e($it[1]) ?></div>
        <?php else: ?>
          <a href="<?= e($it[0]) ?>" class="<?= $active === $it[3] ? 'active' : '' ?>">
            <?= icon($it[2]) ?><span><?= e($it[1]) ?></span>
            <?php if (!empty($it[4])): ?><em class="pill"><?= (int)$it[4] ?></em><?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">Royal University of Bhutan</div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn" id="navToggle" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <h1><?= e($title) ?></h1>
      <div class="topbar-right">
        <span class="net-chip <?= on_office_network() ? 'on' : 'off' ?>" title="Your IP: <?= e(client_ip()) ?>">
          <?= icon('wifi') ?><?= on_office_network() ? 'College network' : 'Outside network' ?>
        </span>
        <div class="user-chip">
          <div class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></div>
          <div class="who"><strong><?= e($u['name']) ?></strong><span><?= e(role_label($u['role'])) ?></span></div>
        </div>
        <form method="post" action="/logout.php" class="inline">
          <?= csrf_field() ?>
          <button class="icon-btn" type="submit" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></button>
        </form>
      </div>
    </header>
    <main class="content">
<?php else: ?>
<main class="content-bare">
<?php endif; ?>
<?php
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alert alert-' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function render_footer(): void
{
    $u = current_user();
    ?>
    </main>
<?php if ($u): ?>
  </div>
</div>
<?php endif; ?>
</body>
</html>
<?php
}
