<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}
csrf_verify();

$today = date('Y-m-d');
$ds = device_state($u);

if (!$u['track_attendance']) {
    flash('error', 'Attendance is not tracked for your account.');
} elseif (!on_office_network()) {
    flash('error', 'You must be on the college network to check out.');
} elseif ($ds['state'] !== 'approved') {
    flash('error', 'This device is not approved for attendance.');
} else {
    $row = q('SELECT id, check_in FROM attendance WHERE user_id = ? AND att_date = ?', [$u['id'], $today])->fetch();
    if (!$row || !$row['check_in']) {
        flash('error', 'You have not checked in today, so there is nothing to check out of.');
    } else {
        q('UPDATE attendance SET check_out = ? WHERE id = ?', [date('H:i:s'), $row['id']]);
        flash('ok', 'Checked out at ' . fmt_time(date('H:i:s')) . '. Have a good evening!');
    }
}
redirect('/dashboard.php');
