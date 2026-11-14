<?php
require __DIR__ . '/../../app/bootstrap.php';
$me = require_perm('manage_leave');

const LEAVE_CSV_COLUMNS = ['staff_email', 'type', 'from_date', 'to_date', 'part', 'until_time', 'reason', 'ref'];

if (isset($_GET['template'])) {
    csv_download('leave-import-template.csv', LEAVE_CSV_COLUMNS, [
        ['pdorji.cnr@rub.edu.bt', 'casual', '2026-11-16', '2026-11-18', 'full', '', 'Family matter', ''],
        ['kwangchuk.cnr@rub.edu.bt', 'tour', '2026-11-20', '2026-11-22', '', '', 'Field visit, Samtse', 'SUB-1042'],
        ['schoden.cnr@rub.edu.bt', 'casual', '2026-11-19', '2026-11-19', 'first_half', '', '', ''],
        ['tlhamo.cnr@rub.edu.bt', 'late_permission', '2026-11-17', '2026-11-17', '', '10:00', 'Hospital visit', ''],
    ]);
}

$importResults = null;
$importDry = false;

if (is_post()) {
    csrf_verify();
    $action = post('action');

    if ($action === 'save') {
        $id = (int)post('id');
        $res = leave_upsert([
            'id' => $id, 'user_id' => (int)post('user_id'), 'type' => post('type'), 'from' => post('from'),
            'to' => post('to'), 'part' => post('part'), 'until' => post('until'), 'reason' => post('reason'),
        ], 'manual', (int)$me['id']);
        if (!$res['ok']) {
            flash('error', $res['error']);
            redirect('/admin/leaves.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        audit('leave_' . $res['action'], $res['user'] . ' ' . post('type') . ' ' . post('from') . ' - ' . (post('to') ?: post('from')));
        flash('ok', ($res['action'] === 'unchanged' ? 'Nothing changed for ' : 'Saved for ') . $res['user']
            . '. Attendance reports now reflect this, including past days.');
        redirect('/admin/leaves.php');
    }

    if ($action === 'delete') {
        $row = q('SELECT l.*, u.name FROM leaves l JOIN users u ON u.id = l.user_id WHERE l.id = ?', [(int)post('id')])->fetch();
        if ($row) {
            q('DELETE FROM leaves WHERE id = ?', [$row['id']]);
            recompute_late((int)$row['user_id'], $row['start_date'], $row['end_date']);
            audit('leave_delete', $row['name'] . ' ' . $row['leave_type'] . ' ' . $row['start_date'] . ' - ' . $row['end_date']);
            flash('ok', 'Removed. Those days go back to normal attendance rules.');
        }
        redirect('/admin/leaves.php');
    }

    if ($action === 'import') {
        $f = $_FILES['file'] ?? null;
        $importDry = isset($_POST['dry_run']);
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2097152) {
            flash('error', 'Choose a CSV file smaller than 2 MB.');
            redirect('/admin/leaves.php');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f['tmp_name']));
        $importResults = [];
        $n = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
            if (trim($line) === '') { continue; }
            $c = array_pad(array_map('trim', str_getcsv($line)), 8, '');
            if (in_array(leave_norm_key($c[0]), ['staff_email', 'email', 'staff_id'], true)) { continue; } // header row
            if (++$n > 2000) { break; }
            $res = leave_upsert(['email' => $c[0], 'type' => $c[1], 'from' => $c[2], 'to' => $c[3], 'part' => $c[4],
                'until' => $c[5], 'reason' => $c[6], 'ref' => $c[7]], 'csv', (int)$me['id'], $importDry);
            $importResults[] = ['line' => $i + 1, 'c' => $c, 'r' => $res];
        }
        if (!$importDry) {
            $sum = array_count_values(array_map(fn($x) => $x['r']['action'], $importResults));
            audit('leave_import', json_encode($sum));
        }
    }

    if ($action === 'gen_token') {
        $token = bin2hex(random_bytes(24));
        save_setting('api_token_hash', hash('sha256', $token));
        audit('api_token_generated');
        flash('ok', 'New API token (copy it now, it is shown only once): ' . $token);
        redirect('/admin/leaves.php#api');
    }
    if ($action === 'disable_api') {
        save_setting('api_token_hash', '');
        audit('api_disabled');
        flash('ok', 'The API is switched off. Nothing can push leave until you generate a new token.');
        redirect('/admin/leaves.php#api');
    }
    if ($action === 'save_ips') {
        $nets = preg_split('/[\s,;]+/', post('api_allowed_ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bad = array_filter($nets, fn($n) => !valid_cidr($n));
        if ($bad) {
            flash('error', '"' . reset($bad) . '" is not a valid IP address or network.');
        } else {
            save_setting('api_allowed_ips', implode("\n", $nets));
            audit('api_ips_saved', implode(' ', $nets));
            flash('ok', $nets ? 'The API now accepts requests only from those addresses.' : 'No address restriction: any address with the token can use the API.');
        }
        redirect('/admin/leaves.php#api');
    }
}

/* ---------------- display ---------------- */
$editing = null;
if (isset($_GET['new'])) {
    $editing = ['id' => 0, 'user_id' => 0, 'leave_type' => 'casual', 'start_date' => '', 'end_date' => '', 'part' => 'full',
        'until_time' => null, 'reason' => ''];
} elseif (isset($_GET['edit'])) {
    $editing = q('SELECT * FROM leaves WHERE id = ?', [(int)$_GET['edit']])->fetch() ?: null;
}

render_header('Leave Register', 'a_leave');

if ($editing):
    $staffList = q('SELECT u.id, u.name, u.staff_id, d.code FROM users u LEFT JOIN departments d ON d.id = u.department_id
                    WHERE u.active = 1 AND u.track_attendance = 1 OR u.id = ? ORDER BY u.name', [(int)$editing['user_id']])->fetchAll();
?>
<div class="card" style="max-width:820px">
  <h2><?= $editing['id'] ? 'Edit' : 'Add' ?> leave, tour or permission</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
    <div class="form-grid">
      <div class="field"><label for="user_id">Staff member</label>
        <select id="user_id" name="user_id" required><option value="">Choose…</option>
          <?php foreach ($staffList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$editing['user_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e($s['staff_id']) ?><?= $s['code'] ? ' · ' . e($s['code']) : '' ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="leave_type">Type</label>
        <select id="leave_type" name="type" required>
          <?php foreach (LEAVE_TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $editing['leave_type'] === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="from">From</label><input type="date" id="from" name="from" required value="<?= e($editing['start_date']) ?>"></div>
      <div class="field"><label for="to">To <span class="muted">(leave blank for one day)</span></label><input type="date" id="to" name="to" value="<?= e($editing['end_date'] !== $editing['start_date'] ? $editing['end_date'] : '') ?>"></div>
      <div class="field not-permission"><label for="part">Day part</label>
        <select id="part" name="part"><?php foreach (LEAVE_PARTS as $k => $l): ?><option value="<?= e($k) ?>" <?= $editing['part'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <div class="hint">Half-day leave counts as 0.5 day and must be a single day. A morning half-day means they are not late if they arrive by the end of lunch.</div></div>
      <div class="field only-permission"><label for="until">Allowed arrival time</label>
        <input type="time" id="until" name="until" value="<?= e(substr((string)$editing['until_time'], 0, 5)) ?>">
        <div class="hint">Arriving up to this time is not counted late.</div></div>
    </div>
    <div class="field"><label for="reason">Reason / remarks <span class="muted">(private: heads and deans only see "On leave" or "On official duty")</span></label>
      <input type="text" id="reason" name="reason" maxlength="255" value="<?= e($editing['reason']) ?>"></div>
    <div class="actions"><button class="btn" type="submit">Save</button><a class="btn btn-light" href="/admin/leaves.php">Cancel</a></div>
  </form>
</div>
<?php else:
    $today = date('Y-m-d');
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to'] ?? date('Y-m-d', strtotime('+90 days'));
    if (!valid_date($from)) { $from = date('Y-m-d', strtotime('-30 days')); }
    if (!valid_date($to))   { $to = date('Y-m-d', strtotime('+90 days')); }
    $ftype = $_GET['type'] ?? '';
    $search = trim($_GET['q'] ?? '');

    $sql = 'SELECT l.*, u.name, u.staff_id, d.name AS dept FROM leaves l JOIN users u ON u.id = l.user_id
            LEFT JOIN departments d ON d.id = u.department_id WHERE l.start_date <= ? AND l.end_date >= ?';
    $p = [$to, $from];
    if (isset(LEAVE_TYPES[$ftype])) { $sql .= ' AND l.leave_type = ?'; $p[] = $ftype; }
    if ($search !== '') {
        $sql .= ' AND (u.name LIKE ? OR u.staff_id LIKE ? OR u.email LIKE ?)';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        array_push($p, $like, $like, $like);
    }
    $sql .= ' ORDER BY l.start_date DESC, u.name LIMIT 300';
    $rows = q($sql, $p)->fetchAll();

    $apiOn = setting('api_token_hash') !== '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $endpoint = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'attendance.example') . '/api/leave.php';
?>
<div class="alert alert-info">Record leave, tours, training and late-arrival permissions here. You can add or change them <strong>at any time</strong>, even weeks later: the late and absent lists, reports and dashboard update straight away, and late marks are re-worked for the days affected.</div>

<div class="card">
  <div class="card-head"><h2>Leave register</h2><a class="btn" href="?new=1">+ Add</a></div>
  <form method="get" class="filters">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label for="type">Type</label>
      <select id="type" name="type"><option value="">All types</option>
        <?php foreach (LEAVE_TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $ftype === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="min-width:220px"><label for="q">Staff</label><input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, ID or email"></div>
    <button class="btn btn-light" type="submit">Show</button>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Staff</th><th>Type</th><th>When</th><th>Days</th><th>Source</th><th>Remarks</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing recorded in this period.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $kind = leave_kind($r['leave_type']); ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong><div class="small muted"><?= e($r['staff_id']) ?> · <?= e($r['dept'] ?? 'No department') ?></div></td>
        <td><span class="badge badge-<?= $kind === 'leave' ? 'info' : ($kind === 'duty' ? 'ok' : 'warn') ?>"><?= e(leave_type_label($r['leave_type'])) ?></span></td>
        <td class="nowrap"><?= e($r['start_date'] === $r['end_date'] ? fmt_date($r['start_date']) : date('d M Y', strtotime($r['start_date'])) . ' – ' . date('d M Y', strtotime($r['end_date']))) ?>
          <?php if ($kind === 'permission'): ?><div class="small muted">arrive by <?= e(fmt_time($r['until_time'])) ?></div>
          <?php elseif ($r['part'] !== 'full'): ?><div class="small muted"><?= $r['part'] === 'first_half' ? 'morning off' : 'afternoon off' ?></div><?php endif; ?></td>
        <td><?= $kind === 'permission' ? '—' : e(fmt_days(leave_days_count($r))) ?></td>
        <td><span class="badge badge-muted"><?= e($r['source']) ?></span><?= $r['external_ref'] ? '<div class="small muted">' . e($r['external_ref']) . '</div>' : '' ?></td>
        <td class="small"><?= e($r['reason'] ?: '—') ?></td>
        <td class="right"><div class="actions" style="justify-content:flex-end">
          <a class="btn btn-light btn-sm" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" class="inline" data-confirm="Remove this entry?">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) === 300): ?><p class="small muted">Showing the first 300 entries. Narrow the dates or search to see others.</p><?php endif; ?>
</div>

<?php if ($importResults !== null):
    $sum = array_count_values(array_map(fn($x) => $x['r']['action'], $importResults)); ?>
<div class="card">
  <h2><?= $importDry ? 'Preview — nothing has been saved yet' : 'Import finished' ?></h2>
  <p>
    <span class="badge badge-ok"><?= (int)($sum['created'] ?? 0) ?> <?= $importDry ? 'would be added' : 'added' ?></span>
    <span class="badge badge-info"><?= (int)($sum['updated'] ?? 0) ?> <?= $importDry ? 'would be updated' : 'updated' ?></span>
    <span class="badge badge-muted"><?= (int)($sum['unchanged'] ?? 0) ?> already there</span>
    <span class="badge badge-bad"><?= (int)($sum['failed'] ?? 0) ?> problem(s)</span>
  </p>
  <?php if ($importDry): ?><p class="small muted">Looks right? Choose the same file again, untick "Preview only", and press Import.</p><?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Line</th><th>Staff</th><th>Type</th><th>From – to</th><th>Result</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($importResults, 0, 400) as $x): ?>
      <tr>
        <td><?= (int)$x['line'] ?></td>
        <td><?= e($x['r']['user'] ?? $x['c'][0]) ?></td>
        <td><?= e($x['c'][1]) ?></td>
        <td class="nowrap"><?= e($x['c'][2]) ?><?= $x['c'][3] !== '' ? ' – ' . e($x['c'][3]) : '' ?></td>
        <td><?php if ($x['r']['ok']): ?><span class="badge badge-ok"><?= e($x['r']['action']) ?></span>
            <?php else: ?><span class="badge badge-bad">not imported</span> <span class="small"><?= e($x['r']['error']) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Import from a CSV file</h2>
    <p class="small muted">For leave approved in IMS, or an export from the academic system. People are matched by <strong>email</strong> (or staff ID). Uploading the same file twice does not create duplicates.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="import">
      <div class="field"><label for="file">CSV file</label><input type="file" id="file" name="file" accept=".csv,.txt" required></div>
      <div class="field"><label class="check"><input type="checkbox" name="dry_run" checked> Preview only (check the file without saving)</label></div>
      <div class="actions"><button class="btn" type="submit">Check / import</button><a class="btn btn-light" href="?template=1">Download template</a></div>
    </form>
    <p class="hint" style="margin-top:12px">Columns: <code><?= e(implode(', ', LEAVE_CSV_COLUMNS)) ?></code><br>
      <code>type</code>: <?= e(implode(', ', array_keys(LEAVE_TYPES))) ?> (names like "Casual leave", "travel" or "workshop" also work).<br>
      <code>part</code>: full (default), first_half or second_half. <code>until_time</code>: only for late_permission, e.g. 10:00. <code>ref</code>: optional id from the other system.<br>
      Dates can be YYYY-MM-DD or DD/MM/YYYY. In Excel use <em>Save as CSV</em>.</p>
  </div>

  <div class="card" id="api">
    <h2>Academic system link (API)</h2>
    <p>Status: <?= $apiOn ? '<span class="badge badge-ok">On</span>' : '<span class="badge badge-muted">Off</span>' ?></p>
    <p class="small muted">The substitution form can send approved travel, training, workshop and block day / week entries here automatically. Developer notes are in <code>docs/LEAVE_API.md</code>.</p>
    <div class="field"><label>Endpoint</label><input type="text" readonly value="<?= e($endpoint) ?>" onclick="this.select()"></div>
    <div class="actions" style="margin-bottom:16px">
      <form method="post" class="inline" data-confirm="<?= $apiOn ? 'This replaces the current token. The academic system must be updated with the new one. Continue?' : 'Generate an API token?' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="gen_token"><button class="btn" type="submit"><?= $apiOn ? 'Replace token' : 'Generate token' ?></button></form>
      <?php if ($apiOn): ?>
      <form method="post" class="inline" data-confirm="Switch the API off?">
        <?= csrf_field() ?><input type="hidden" name="action" value="disable_api"><button class="btn btn-light" type="submit">Switch off</button></form>
      <?php endif; ?>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_ips">
      <div class="field"><label for="api_allowed_ips">Only accept requests from these addresses <span class="muted">(optional)</span></label>
        <textarea id="api_allowed_ips" name="api_allowed_ips" style="min-height:70px"><?= e(setting('api_allowed_ips')) ?></textarea>
        <div class="hint">One IP or network per line, e.g. the academic system's server. Leave empty to allow any address that has the token.</div></div>
      <button class="btn btn-light" type="submit">Save addresses</button>
    </form>
  </div>
</div>
<?php endif;
render_footer();
