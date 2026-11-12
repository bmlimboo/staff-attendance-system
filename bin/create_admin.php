<?php
/**
 * Creates the first System Administrator.
 * Run on the server:   php bin/create_admin.php
 */
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require __DIR__ . '/../app/bootstrap.php';

function ask(string $label, bool $hidden = false): string
{
    echo $label . ': ';
    if ($hidden) { shell_exec('stty -echo 2>/dev/null'); }
    $v = trim((string)fgets(STDIN));
    if ($hidden) { shell_exec('stty echo 2>/dev/null'); echo "\n"; }
    return $v;
}

$staffId = ask('Staff ID');
$name    = ask('Full name');
$email   = mb_strtolower(ask('Email'));
$pw      = ask('Password (min 8 characters)', true);
$pw2     = ask('Repeat password', true);

if ($staffId === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Staff ID, name and a valid email are required.\n");
}
if (strlen($pw) < 8 || $pw !== $pw2) {
    exit("Passwords must match and be at least 8 characters.\n");
}

$dept = q("SELECT id FROM departments WHERE code = 'ADMIN'")->fetch();
try {
    q("INSERT INTO users (staff_id, name, email, password_hash, role, staff_type, department_id, track_attendance, must_change_password)
       VALUES (?,?,?,?, 'sysadmin', 'non_teaching', ?, 0, 0)",
        [$staffId, $name, $email, password_hash($pw, PASSWORD_DEFAULT), $dept['id'] ?? null]);
    echo "System Administrator created. You can now sign in as $email\n";
    echo "(Attendance tracking is off for this account; turn it on under Staff & Users if you want to be tracked too.)\n";
} catch (PDOException $e) {
    exit($e->getCode() === '23000' ? "That staff ID or email already exists.\n" : "Database error: " . $e->getMessage() . "\n");
}
