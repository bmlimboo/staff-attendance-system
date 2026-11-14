# Leave API — for the academic system (substitution form)

The attendance system can receive approved travel, training, workshop and block day / week entries automatically, so nobody has to type them twice. Once an entry arrives, staff are no longer shown as absent for those days, even for days that have already passed.

## Set up (once)

1. Sign in as the System Administrator → **Leave Register → Academic system link (API)**.
2. Press **Generate token** and copy it. It is shown only once. Treat it like a password.
3. Optional but recommended: under *Only accept requests from these addresses*, enter the academic system server's IP.
4. Use the HTTPS address of the site once HTTPS is set up. Over plain HTTP the token travels unencrypted.

## Request

```
POST https://attendance.cnr.edu.bt/api/leave.php
X-Api-Token: <your token>          (or  Authorization: Bearer <your token>)
Content-Type: application/json
```

Send one entry, a list of entries, or `{"leaves": [ ... ]}` (maximum 500 per request).

| Field | Required | Meaning |
|---|---|---|
| `email` | yes* | Staff email. *Or send `staff_id` instead. |
| `type` | yes | `tour`, `training`, `block_day`, `block_week` (also accepted: `travel`, `workshop`, `casual`, `annual`, `medical`, `maternity`, `paternity`, `medical_escort`, `eol`, `late_permission`) |
| `from` | yes | First day, `YYYY-MM-DD` |
| `to` | no | Last day (default: same as `from`) |
| `part` | no | `full` (default), `first_half` or `second_half` (single day only) |
| `until` | late permission only | Allowed arrival time, e.g. `10:00` |
| `reason` | no | Free text. Kept private: heads and deans only see "On official duty" |
| `ref` | **strongly recommended** | The form's own id, e.g. `SUB-1042`. Sending the same `ref` again **updates** the entry instead of creating a duplicate |
| `status` | no | `approved` (default) is saved. `cancelled`, `rejected` or `withdrawn` removes the entry with that `ref`. Anything else (for example `pending`) is skipped |

### Example

```bash
curl -X POST https://attendance.cnr.edu.bt/api/leave.php \
  -H "X-Api-Token: YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"ref":"SUB-1042","email":"kwangchuk.cnr@rub.edu.bt","type":"tour",
       "from":"2026-11-20","to":"2026-11-22","reason":"Field visit, Samtse"}'
```

### Example from PHP (inside the academic system)

```php
function push_leave_to_attendance(array $form): array
{
    $payload = [
        'ref'    => 'SUB-' . $form['id'],
        'email'  => $form['staff_email'],
        'type'   => 'tour',                       // or training / block_day / block_week
        'from'   => $form['travel_from'],
        'to'     => $form['travel_to'],
        'reason' => $form['reason'],
        'status' => $form['status'],              // approved / cancelled ...
    ];
    $ch = curl_init('https://attendance.cnr.edu.bt/api/leave.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Token: ' . ATTENDANCE_API_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$body, true) ?: [];
}
```

Call it when a form is approved, when it is changed, and when it is withdrawn or rejected.

## Response

```json
{
  "ok": true,
  "summary": {"created": 1, "updated": 0, "unchanged": 0, "cancelled": 0, "skipped": 0, "failed": 0},
  "results": [{"index": 0, "ref": "SUB-1042", "status": "created", "id": 17}]
}
```

`ok` is `false` when any entry failed; each failed entry has an `error`, for example `No staff member found for "x@y.bt"`. Other entries in the same request are still saved.

| HTTP code | Meaning |
|---|---|
| 200 | Processed (check `ok` and each result) |
| 400 | Not valid JSON, or more than 500 entries |
| 401 | Missing or wrong token |
| 403 | Caller address not on the allow-list |
| 429 | Too many wrong tokens, wait 15 minutes |
| 503 | API switched off |

## Notes

- Staff are matched by **email** (or `staff_id`). Make sure the email in the academic system equals the email in Staff & Users.
- Entries made through the API show as source `api` in the Leave Register. The administrator can still edit or delete them, but a later push with the same `ref` will overwrite those edits.
- Leave added later is applied to past days too, and late marks for those days are worked out again.
