<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$manage = can('manage');

/* ---------- helpers for importing a college calendar ---------- */
function parse_any_date(string $s): ?string
{
    $s = trim($s);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Ymd'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d && $d->format($fmt) === $s) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

function date_span(string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from), $n = 0; $t <= strtotime($to) && $n < 60; $t = strtotime('+1 day', $t), $n++) {
        $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/** @return array<string,string> date => title */
function parse_csv_holidays(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') { continue; }
        $c = str_getcsv($line);
        $start = parse_any_date($c[0] ?? '');
        if (!$start) { continue; } // header row or bad line
        $title = trim($c[1] ?? '') ?: 'Holiday';
        $end   = parse_any_date($c[2] ?? '') ?: $start;
        foreach (date_span($start, max($start, $end)) as $d) {
            $out[$d] = $title;
        }
    }
    return $out;
}

/** Reads an .ics calendar exported from Google Calendar, Outlook, etc. */
function parse_ics_holidays(string $text): array
{
    $text = preg_replace("/\r?\n[ \t]/", '', $text); // unfold long lines
    $out = [];
    foreach (preg_split('/BEGIN:VEVENT/', $text) as $i => $block) {
        if ($i === 0) { continue; }
        $block = explode('END:VEVENT', $block)[0];
        $start = $end = $title = null;
        $allDayEnd = false;
        foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
            if (preg_match('/^DTSTART[^:]*:(\d{8})/i', $line, $m)) {
                $start = parse_any_date($m[1]);
            } elseif (preg_match('/^DTEND[^:]*:(\d{8})(T\d+Z?)?/i', $line, $m)) {
                $end = parse_any_date($m[1]);
                $allDayEnd = empty($m[2]); // all-day events have an exclusive end date
            } elseif (preg_match('/^SUMMARY[^:]*:(.*)$/i', $line, $m)) {
                $title = trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $m[1]));
            }
        }
        if (!$start) { continue; }
        if ($end && $allDayEnd) {
            $end = date('Y-m-d', strtotime('-1 day', strtotime($end))); // DTEND is exclusive
        }
        $end = ($end && $end >= $start) ? $end : $start;
        foreach (date_span($start, $end) as $d) {
            $out[$d] = mb_substr($title ?: 'Holiday', 0, 150);
        }
    }
    return $out;
}

/* ---------- actions (administrator only) ---------- */
if (is_post()) {
    if (!$manage) {
        http_response_code(403);
        exit('Not allowed.');
    }
    csrf_verify();
    $action = post('action');

    if ($action === 'add') {
        $d1 = post('date');
        $d2 = post('end_date') ?: $d1;
        $title = mb_substr(post('title'), 0, 150);
        if (!valid_date($d1) || !valid_date($d2) || $d2 < $d1 || $title === '') {
            flash('error', 'Enter a title and a valid date (end date cannot be before the start date).');
        } else {
            $n = 0;
            foreach (date_span($d1, $d2) as $d) {
                q('INSERT INTO holidays (holiday_date, title) VALUES (?,?) ON DUPLICATE KEY UPDATE title = VALUES(title)', [$d, $title]);
                $n++;
            }
            audit('holiday_add', "$title ($d1 - $d2)");
            flash('ok', "Added $n day(s): $title.");
        }
    } elseif ($action === 'delete') {
        q('DELETE FROM holidays WHERE id = ?', [(int)post('id')]);
        audit('holiday_delete', 'id ' . (int)post('id'));
        flash('ok', 'Holiday removed.');
    } elseif ($action === 'import') {
        $f = $_FILES['calendar'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1048576) {
            flash('error', 'Please choose a .csv or .ics file smaller than 1 MB.');
        } else {
            $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $text = (string)file_get_contents($f['tmp_name']);
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
            if ($ext === 'ics') {
                $found = parse_ics_holidays($text);
            } elseif ($ext === 'csv' || $ext === 'txt') {
                $found = parse_csv_holidays($text);
            } else {
                $found = null;
                flash('error', 'Only .csv and .ics files are supported.');
            }
            if ($found !== null) {
                foreach ($found as $d => $t) {
                    q('INSERT INTO holidays (holiday_date, title) VALUES (?,?) ON DUPLICATE KEY UPDATE title = VALUES(title)', [$d, $t]);
                }
                audit('holiday_import', count($found) . ' days from ' . $f['name']);
                flash($found ? 'ok' : 'warn', $found ? 'Imported ' . count($found) . ' holiday day(s).' : 'No holidays found in that file. Check the format below.');
            }
        }
    }
    redirect('/holidays.php?year=' . (int)post('year', date('Y')));
}

/* ---------- display ---------- */
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }
$list = q('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date', ["$year-01-01", "$year-12-31"])->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    csv_download("college-holidays-$year.csv", ['Date', 'Title'], array_map(fn($h) => [$h['holiday_date'], $h['title']], $list));
}

render_header('College Calendar', 'holidays');
?>
<div class="grid grid-2" style="grid-template-columns:1.5fr 1fr">
  <div class="card">
    <div class="card-head">
      <h2>Holidays in <?= $year ?></h2>
      <div class="actions">
        <a class="btn btn-light btn-sm" href="?year=<?= $year - 1 ?>">← <?= $year - 1 ?></a>
        <a class="btn btn-light btn-sm" href="?year=<?= $year + 1 ?>"><?= $year + 1 ?> →</a>
        <a class="btn btn-light btn-sm" href="?year=<?= $year ?>&export=csv">Export CSV</a>
      </div>
    </div>
    <p class="small muted">No attendance is expected on these days, and nobody is marked late or absent.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Holiday</th><?php if ($manage): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="3" class="empty">No holidays added for <?= $year ?> yet.</td></tr><?php endif; ?>
      <?php foreach ($list as $h): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_date($h['holiday_date'])) ?></td>
          <td><?= e($h['title']) ?></td>
          <?php if ($manage): ?>
          <td class="right">
            <form method="post" class="inline" data-confirm="Remove this holiday?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><input type="hidden" name="year" value="<?= $year ?>">
              <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <?php if ($manage): ?>
  <div>
    <div class="card">
      <h2>Add a holiday</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="year" value="<?= $year ?>">
        <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" maxlength="150" required placeholder="e.g. Winter break"></div>
        <div class="field"><label for="date">Date (or start date)</label><input type="date" id="date" name="date" required></div>
        <div class="field"><label for="end_date">End date <span class="muted">(optional, for a range)</span></label><input type="date" id="end_date" name="end_date"></div>
        <button class="btn" type="submit">Add holiday</button>
      </form>
    </div>
    <div class="card">
      <h2>Import college calendar</h2>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="year" value="<?= $year ?>">
        <div class="field"><label for="calendar">.ics or .csv file</label><input type="file" id="calendar" name="calendar" accept=".ics,.csv,.txt" required></div>
        <button class="btn" type="submit">Import</button>
      </form>
      <p class="hint" style="margin-top:12px"><strong>CSV format</strong> — one holiday per line: <code>date,title[,end date]</code><br>
        e.g. <code>2026-12-17,National Day</code> or <code>2026-12-21,Winter break,2027-02-27</code>. Dates may be YYYY-MM-DD or DD/MM/YYYY.<br>
        <strong>.ics</strong> files exported from Google Calendar or Outlook also work. Existing dates are updated, nothing is deleted.</p>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php render_footer();
