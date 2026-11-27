# D5A maintenance and retained delivery

Use these controls only from a verified, newly built D5A package on an authorized target.
The existing D2/D3 archives do not contain D4 or D5A. Privileged native acceptance is still
required. Do not copy this source over a running installation or run live migrations to
enable it. Fresh installation initializes identity only after its additive schema exists.
Reinstall/update/restore/backup/reset/uninstall paths remain guarded until the later D5
transaction engines are implemented. No Update button or internet polling is provided.

One main computer owns the database and collectors. Browser clients remain D4 client-only
helpers at https://philcst.vms.internal. TLS issuer keys and update trust are separate from
the protected local installation/generation identity described here.

## Commands

Windows, PowerShell **as Administrator**, using the installed bundled runtime:

```powershell
$root='C:\PHILCST-VMS'
$py="$root\runtime\python\python.exe"
$ctl="$root\app\deploy\lifecycle\maintenance.py"
& $py -B $ctl status --root $root
$operation=[guid]::NewGuid().ToString()
& $py -B $ctl enter --root $root --operation $operation --producer-wait-seconds 30 --drain-seconds 10
& $py -B $ctl verify --root $root --operation $operation
# Only after the authorized maintenance work and consistency checks:
& $py -B $ctl resume --root $root --operation $operation
```

Mac, Terminal with explicit sudo:

```sh
ROOT='/Library/Application Support/PHILCST-VMS'
PY="$ROOT/current/runtime/python/bin/python3"
CTL="$ROOT/current/app/deploy/lifecycle/maintenance.py"
sudo "$PY" -B "$CTL" status --root "$ROOT"
OPERATION=$(uuidgen)
sudo "$PY" -B "$CTL" enter --root "$ROOT" --operation "$OPERATION" --producer-wait-seconds 30 --drain-seconds 10
sudo "$PY" -B "$CTL" verify --root "$ROOT" --operation "$OPERATION"
sudo "$PY" -B "$CTL" resume --root "$ROOT" --operation "$OPERATION"
```

The operation ID must be retained until explicit Resume. A second owner cannot replace an
unfinished operation. Defaults permit 30 seconds for already-started submission work and
10 seconds for transport drain, bounded to 60 each; an HTTP attempt can add its configured
connect/read timeout. Owned OS stop/readiness checks have separate bounded waits. Never
interpret a timeout or service display alone as proof that a backup is safe.

Enter closes operational admission, waits for registered submission work to persist/finish,
stops owned capture and scheduled producers, drains where feasible, reserves the cross-runtime
writer barrier, stops remaining writers and verifies them. No registered active submission
is intentionally killed to report quiescence. If its grace period expires, state remains
`quiescing`, not ready: allow it to finish and repeat Enter with **the same operation ID**,
or Resume to cancel admission-only maintenance after consistency/readiness checks. Detection
windows which have not yet become a submission may be interrupted by an intentional pause;
no movement is invented to flush them.

`quiescent` means the verified cut has no unacknowledged deliveries. `quiescent_pending`
means the same writer cut with explicitly retained pending/in-flight/dead/blocked operations.
It is usable by a future D5B engine only if that engine preserves the entire outbox,
evidence, identities, receipts and maintenance state. D5A does not create a coherent backup.

Resume verifies local SQLite integrity, installation/database identity, secrets and D4
issuer readiness before opening access, then checks HTTP/HTTPS readiness before capture and
tasks reopen. Failure stays fenced. A healthy service display or a busy port is not ownership.
Do not delete maintenance/identity files, replace missing encryption keys or reset a spool
to make a failed resume succeed.

## Delivery status and review

Status reports pending/in-flight/dead/acked counts, oldest age, retained/allocated bytes,
free disk, retained retry counts, failures/operation IDs, dependencies, active producer work
and delivery readiness. `ready` concerns capture capacity; `delivery_ready` also considers
maintenance and errors. Counts for acknowledged history cover a bounded retained window,
not a lifetime audit. Application receipts remain in the canonical DB.

Existing detector/device status JSON also includes delivery diagnostics. No extra product
page was added. Full capacity pauses/refuses new capture; pending work is never evicted.
The defaults are 5,000 unacknowledged operations, 512 MiB encoded work, a 32 MiB SQLite
metadata allowance and 256 MiB minimum free disk. Acknowledged media is released only after
a verified receipt; about 1,000 small acknowledgement records remain. Dead operations and
their dependent work continue consuming capacity until resolved through reviewed procedures.

For an operation ID reported by Status:

```powershell
& $py -B $ctl inspect --root $root --delivery '64_HEX_OPERATION_ID'
& $py -B $ctl export-evidence --root $root --delivery '64_HEX_OPERATION_ID' --output 'E:\Private-Review\New-Folder'
# After correcting credentials or the actual verified cause, without editing the payload:
& $py -B $ctl retry --root $root --delivery '64_HEX_OPERATION_ID'
```

```sh
sudo "$PY" -B "$CTL" inspect --root "$ROOT" --delivery 64_HEX_OPERATION_ID
sudo "$PY" -B "$CTL" export-evidence --root "$ROOT" --delivery 64_HEX_OPERATION_ID --output /Volumes/USB/Private-New-Review
sudo "$PY" -B "$CTL" retry --root "$ROOT" --delivery 64_HEX_OPERATION_ID
```

Inspect exposes selected context/checksums, not arbitrary payloads/API keys. Export validates
original image bytes and writes a new private folder outside the installation; its review
manifest is written last. It does not change queued data or serve images through a web route.
Keep these images private. Never infer a camera from its current gate assignment, recapture
a scene, generate a replacement event key or alter a payload to get acceptance.

Timeouts, 408/429 and server/maintenance failures retry with persisted bounded backoff; a 429
Retry-After is honored within the bound. An arbitrary 200 is not acceptance. Matching
committed/verified receipts bind operation SHA, installation and generation. Other 4xx
failures and the 20-attempt budget move work to visible dead state, retaining media. Explicit
Retry preserves the original ID/content and keeps review/total-attempt counters. A failed
initial operation holds dependent evidence and subsequent operations in that gate's lane.

If an archived gate/reassigned camera makes the original context invalid, leave that work
retained for an independently authorized historical review; D5A never remaps it automatically.
Some conflicts cannot safely be repaired by retry. Do not delete the row to unblock a lane.
A later approved repair/import procedure must account for the original identity/effects.

## Interrupted ownership and restart

NSSM wrappers, scheduled callbacks and launchd supervisors respect persisted phases. Access
roles may start during owned `resuming`; capture cannot. Standalone installed tools read the
configured lifecycle root even without inherited service environment. Missing state fails
closed. No lock is automatically stolen or phase cleared on reboot.

If an orchestration process died, inspect Status and the recorded owner. Only after reviewing
that exact operation, use `recover --operation ID`. It validates that the recorded PID is
gone and removes only its owned lock. Admission remains closed (`quiescing` before service
changes, otherwise `failed`); Resume is a separate validated action. PID reuse/uncertain
ownership refuses recovery. Missing/corrupt ownership metadata needs later explicit recovery,
not a guessed new identity.

An unexpectedly terminated producer can leave a registered job. Status shows its PID/token.
`review-dead-producers --operation ID` removes only definitely dead producer registrations
and records that review; it does not delete queue items, media or events. Live jobs are kept.
Some in-memory work may not have persisted before that crash and cannot be reconstructed.
Continue Enter with the same owner, or Resume after checks. Never kill a live writer merely
to make Verify green.

Durable delivery protects work that was successfully persisted. It cannot reconstruct vehicles
never captured during shutdown, hardware failure or intentional monitoring pause. Process-kill
fixtures do not certify physical power-loss durability. A local lock cannot stop a different
computer collecting the same devices: transfer ownership/fencing remains D5D.

## Required D5B preservation set

Keep one coherent main application DB (including `delivery_installations`, all
`delivery_receipts`, cache/claims, occupancy, audits and lost-UID state), all referenced/private
media, protected `.env`/APP_KEY/API secrets, TLS issuer/identity/public handoff and separate
update trust, device/gate/calibration state, **the complete lifecycle directory** (identity,
maintenance/operation ownership, producer registrations, coordination SQLite, delivery SQLite
and required journals), service/task/exposure receipts, release/runtime/source identity and
installation marker. Retain unacknowledged media inside the spool even if no app row exists.

D5B must own/hold the maintenance operation while copying and validating that set; a previous
Verify response alone is not a lifetime lock. Restore/recovery must establish a deliberately
validated database generation before any retained work replays. D5C must preserve/migrate
supported outbox/receipt schemas without identity regeneration. D5D must prove one active
collector/server owner across computers. None of those engines is implemented by D5A.
