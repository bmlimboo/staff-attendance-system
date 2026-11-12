<?php
declare(strict_types=1);

const DEVICE_COOKIE = 'cnr_dev';

/* ---------------- current user ---------------- */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $r = q('SELECT u.*, d.name AS dept_name, d.code AS dept_code
                    FROM users u LEFT JOIN departments d ON d.id = u.department_id
                    WHERE u.id = ? AND u.active = 1', [$_SESSION['uid']])->fetch();
            $user = $r ?: null;
            if (!$user) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('/login.php');
    }
    if ($u['must_change_password'] && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php'
        && basename($_SERVER['SCRIPT_NAME']) !== 'logout.php') {
        redirect('/change_password.php');
    }
    return $u;
}

function require_perm(string $perm): array
{
    $u = require_login();
    if (!can($perm)) {
        http_response_code(403);
        render_header('Access denied');
        echo '<div class="card"><h2>Access denied</h2><p>Your role does not have access to this page.</p></div>';
        render_footer();
        exit;
    }
    return $u;
}

/** Require at least one of several permissions. */
function require_any_perm(array $perms): array
{
    $u = require_login();
    foreach ($perms as $p) {
        if (can($p)) {
            return $u;
        }
    }
    http_response_code(403);
    render_header('Access denied');
    echo '<div class="card"><h2>Access denied</h2><p>Your role does not have access to this page.</p></div>';
    render_footer();
    exit;
}

/* ---------------- login throttling ---------------- */
function too_many_attempts(string $identifier): bool
{
    $since = date('Y-m-d H:i:s', time() - 900);
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    $byUser = (int)q('SELECT COUNT(*) c FROM login_attempts WHERE identifier = ? AND attempted_at > ?', [$identifier, $since])->fetch()['c'];
    $byIp   = (int)q('SELECT COUNT(*) c FROM login_attempts WHERE ip_address = ? AND attempted_at > ?', [client_ip(), $since])->fetch()['c'];
    return $byUser >= 5 || $byIp >= 20;
}

function record_failed_login(string $identifier): void
{
    q('INSERT INTO login_attempts (identifier, ip_address, attempted_at) VALUES (?,?,?)',
        [mb_substr($identifier, 0, 150), client_ip(), date('Y-m-d H:i:s')]);
}

function clear_failed_logins(string $identifier): void
{
    q('DELETE FROM login_attempts WHERE identifier = ?', [$identifier]);
}

/* ---------------- devices ---------------- */
function device_token(): ?string
{
    $t = $_COOKIE[DEVICE_COOKIE] ?? '';
    return (is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t)) ? $t : null;
}

function set_device_cookie(string $token): void
{
    $secure = cfg('cookie_secure');
    if ($secure === null) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
    setcookie(DEVICE_COOKIE, $token, [
        'expires'  => time() + 86400 * 730,
        'path'     => '/',
        'secure'   => (bool)$secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * State of THIS browser for the given user:
 *  approved | pending | rejected | revoked | none (browser not registered for this user)
 */
function device_state(array $user): array
{
    $token = device_token();
    $device = null;
    if ($token) {
        $device = q('SELECT * FROM devices WHERE token_hash = ? AND user_id = ?',
            [hash('sha256', $token), $user['id']])->fetch() ?: null;
    }
    $count = (int)q("SELECT COUNT(*) c FROM devices WHERE user_id = ? AND status IN ('pending','approved')",
        [$user['id']])->fetch()['c'];
    return ['state' => $device ? $device['status'] : 'none', 'device' => $device, 'active_count' => $count];
}

/* ---------------- automatic check-in ---------------- */
/**
 * Called right after a successful login. Records attendance when:
 *  - the user is tracked, on the office network, using an approved device,
 *  - today is a working day.
 * Returns ['code' => ..., ...] for the dashboard banner.
 */
function attempt_checkin(array $user): array
{
    if (!$user['track_attendance']) {
        return ['code' => 'not_tracked'];
    }
    if (!on_office_network()) {
        return ['code' => 'offsite'];
    }
    $ds = device_state($user);
    if ($ds['state'] !== 'approved') {
        return ['code' => 'device_' . $ds['state']];
    }

    $today = date('Y-m-d');
    $now   = date('H:i:s');

    if (!is_working_day($today)) {
        $h = holiday_title($today);
        return ['code' => 'non_working', 'holiday' => $h];
    }

    q('UPDATE devices SET last_used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $ds['device']['id']]);

    $row = q('SELECT * FROM attendance WHERE user_id = ? AND att_date = ?', [$user['id'], $today])->fetch();
    if ($row && $row['check_in']) {
        return ['code' => 'already', 'time' => $row['check_in'], 'late' => (int)$row['late_minutes']];
    }
    if ($row && $row['status'] === 'leave') {
        return ['code' => 'leave'];
    }

    $late   = late_minutes($now, $user['staff_type']);
    $status = $late > 0 ? 'late' : 'present';

    if ($row) { // admin pre-created an empty record (e.g. marked absent) - fill it in
        q('UPDATE attendance SET check_in = ?, status = ?, late_minutes = ?, device_id = ?, ip_address = ?, source = ? WHERE id = ?',
            [$now, $status, $late, $ds['device']['id'], client_ip(), 'auto', $row['id']]);
    } else {
        q('INSERT INTO attendance (user_id, att_date, check_in, status, late_minutes, device_id, ip_address, source)
           VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id = id',
            [$user['id'], $today, $now, $status, $late, $ds['device']['id'], client_ip(), 'auto']);
    }
    return ['code' => 'checked_in', 'time' => $now, 'late' => $late];
}
