<?php
/**
 * Leave API for the academic system (substitution form).
 * See docs/LEAVE_API.md.  POST JSON, header  X-Api-Token: <token>
 */
define('NO_SESSION', true);
require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    api_out(405, ['ok' => false, 'error' => 'Use POST with a JSON body.']);
}

$hash = setting('api_token_hash');
if ($hash === '') {
    api_out(503, ['ok' => false, 'error' => 'The leave API is switched off.']);
}

/* optional allow-list of caller addresses */
$nets = preg_split('/[\s,;]+/', setting('api_allowed_ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
if ($nets) {
    $okIp = false;
    foreach ($nets as $n) {
        if (valid_cidr($n) && ip_in_cidr(client_ip(), $n)) {
            $okIp = true;
            break;
        }
    }
    if (!$okIp) {
        api_out(403, ['ok' => false, 'error' => 'This address is not allowed to use the API.']);
    }
}

// Lock out per caller address, so a stranger sending bad tokens cannot block the academic system.
$apiKey = 'api:' . client_ip();
if (too_many_attempts($apiKey)) {
    api_out(429, ['ok' => false, 'error' => 'Too many failed attempts. Try again in 15 minutes.']);
}

$token = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
if ($token === '' && preg_match('/^Bearer\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $token = $m[1];
}
if ($token === '' || !hash_equals($hash, hash('sha256', $token))) {
    record_failed_login($apiKey);
    api_out(401, ['ok' => false, 'error' => 'Missing or wrong token.']);
}

$raw = (string)file_get_contents('php://input', false, null, 0, 1048577);
if (strlen($raw) > 1048576) {
    api_out(413, ['ok' => false, 'error' => 'Request too large (1 MB maximum).']);
}
$data = json_decode($raw, true, 8);
if (!is_array($data)) {
    api_out(400, ['ok' => false, 'error' => 'The body must be valid JSON.']);
}
if ($data === []) {
    api_out(200, ['ok' => true, 'summary' => [], 'results' => []]);
}
if (isset($data['leaves']) && is_array($data['leaves'])) {
    $items = $data['leaves'];
} elseif ($data && array_keys($data) === range(0, count($data) - 1)) {
    $items = $data;               // a plain list of entries
} else {
    $items = [$data];             // a single entry
}
if (count($items) > 500) {
    api_out(400, ['ok' => false, 'error' => 'At most 500 entries per request.']);
}

$results = [];
$summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($items as $i => $it) {
    if (!is_array($it)) {
        $results[] = ['index' => $i, 'ref' => '', 'status' => 'failed', 'error' => 'Each entry must be an object.'];
        $summary['failed']++;
        continue;
    }
    $f = static fn(string $k): string => (isset($it[$k]) && is_scalar($it[$k])) ? trim((string)$it[$k]) : '';
    $ref = $f('ref');
    $status = strtolower($f('status'));

    if (in_array($status, ['cancel', 'cancelled', 'canceled', 'rejected', 'withdrawn', 'deleted'], true) || !empty($it['cancel'])) {
        $res = $ref === ''
            ? ['ok' => false, 'action' => 'failed', 'error' => 'Cancelling needs a "ref".', 'id' => null]
            : leave_cancel_ref('api', $ref);
        if (!$res['ok'] && $ref !== '' && $res['error'] === 'Nothing found with that reference.') {
            $res = ['ok' => true, 'action' => 'skipped', 'error' => null, 'id' => null]; // never imported, nothing to undo
        }
    } elseif ($status !== '' && !in_array($status, ['approved', 'confirmed', 'accepted'], true)) {
        $res = ['ok' => true, 'action' => 'skipped', 'error' => null, 'id' => null];     // pending forms are ignored
    } else {
        $res = leave_upsert([
            'email' => $f('email'), 'staff_id' => $f('staff_id'), 'type' => $f('type'), 'from' => $f('from'), 'to' => $f('to'),
            'part' => $f('part'), 'until' => $f('until'), 'reason' => $f('reason'), 'ref' => $ref,
        ], 'api', null);
    }
    $summary[$res['action']] = ($summary[$res['action']] ?? 0) + 1;
    $row = ['index' => $i, 'ref' => $ref, 'status' => $res['action']];
    if (!empty($res['error'])) { $row['error'] = $res['error']; }
    if (!empty($res['id']))    { $row['id'] = $res['id']; }
    $results[] = $row;
}

audit('api_leave', json_encode($summary));
api_out(200, ['ok' => $summary['failed'] === 0, 'summary' => $summary, 'results' => $results]);
