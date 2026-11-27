# Authenticated updates — D5C operator interface

D5C is source/fixture work. It does not certify an installed update on Windows,
Intel or arm64 macOS. Production paths in `build/updates/compatibility.json` and
`upgrade-paths.json` are empty. D2/D3 unsigned installations must not silently
become authenticated installations. D5D transfer/network changes are separate.

## Requirements and independently verified bootstrap

Use only a newly rebuilt, externally authenticated complete package with D4,
initialized D5A delivery and D5B recovery schema/host/security receipts. The
installed BUILD-INFO and actual SQL migration ledger must agree. Do not copy
these files over a working older installation or initialize missing recovery
metadata to bypass an interrupted operation. A legacy installation needs its own
reviewed migration/adoption plan, coherent backup and native acceptance.

The institution must supply publisher **public** SPKI material in a
`philcst.update-trust.v1` file, with independently delivered canonical SHA-256,
key scope, validity and generation. See `build/updates/README.md` for the full
trust format. Initial trust cannot come from the candidate being imported.
TLS/CA trust and update publisher trust are separate. No fixture/private keys
are installed or distributed by this implementation.

Run commands only as a local OS administrator against an isolated acceptance
installation until release approval. Obtain the exact installed source hash
independently from the authenticated build identity.

Windows, elevated PowerShell 5.1; `ROOT` must be the actual installed folder:

```powershell
$root='C:\PHILCST-VMS'
& "$root\runtime\python\python.exe" -I -B "$root\app\deploy\updates\cli.py" bootstrap-controller --root $root --confirm-source SOURCE_SHA256
$receipt=Get-Content "$root\state\update-controller.json" -Raw | ConvertFrom-Json
$capsule=Join-Path "$root\state\update-controller" $receipt.id
$py=Join-Path $capsule 'runtime\python\python.exe'
$cli=Join-Path $capsule 'app\deploy\updates\cli.py'
& $py -I -B $cli bootstrap-trust --root $root --trust 'E:\verified-publisher-trust.json' --trust-sha256 INDEPENDENT_CANONICAL_SHA256 --confirm-source SOURCE_SHA256
& $py -I -B $cli enable-worker --root $root
```

Mac, native architecture, administrator terminal:

```sh
ROOT='/Library/Application Support/PHILCST-VMS'
sudo "$ROOT/current/runtime/python/bin/python3" -I -B "$ROOT/current/app/deploy/updates/cli.py" bootstrap-controller --root "$ROOT" --confirm-source SOURCE_SHA256
ID=$(/usr/bin/plutil -extract id raw -o - "$ROOT/shared/update-controller.json")
CAPSULE="$ROOT/shared/update-controller/$ID"
PY="$CAPSULE/runtime/python/bin/python3"
CLI="$CAPSULE/app/deploy/updates/cli.py"
sudo "$PY" -I -B "$CLI" bootstrap-trust --root "$ROOT" --trust /Volumes/USB/verified-publisher-trust.json --trust-sha256 INDEPENDENT_CANONICAL_SHA256 --confirm-source SOURCE_SHA256
sudo "$PY" -I -B "$CLI" enable-worker --root "$ROOT"
```

Bootstrap copies an independent prior controller/runtime into protected state,
records its complete tree hash and writes a stable `Controls/Update-Recovery`
entry point. Never remove or overwrite this sole controller during an update.
The optional minute worker processes preparation requests; six-hour discovery
and automatic download preferences remain **off** until explicitly selected.
Installing the worker changes native scheduling and is not performed by source
tests or ordinary page requests. It never installs a software update.

## Preparation and USB/local import

Settings → Advanced → Updates is admin-only. It shows installed identity, actual
check outcome, compatible release notes, preparation/validation result and
maintenance requirements. Check/Download/Import only queue bounded preparation.
A native worker must be explicitly installed or the administrator can run
`tick`. There is no GitHub call during login/startup/ordinary application requests.

Equivalent native commands, using the protected interpreter/controller above:

```text
check --root ROOT
preferences --root ROOT --channel stable --automatic-check --automatic-download
import --root ROOT --manifest FRESH_SIGNED_METADATA --package COMPLETE_PLATFORM_PACKAGE
plan --root ROOT --candidate CANDIDATE_SHA256
status --root ROOT
```

Both local import and online preparation verify installation-trusted signatures,
channel/version/platform/OS/origins, full archive size/hash, inventory, safe paths
and source/runtime identities before any candidate is extracted or executed.
A source ZIP, branch checkout or arbitrary Actions artifact is not an update.
Cache lives in protected `state|shared/update-cache`, separate from active data,
backups/outbox and D5B's promoted `update` trust directory. Limits: 4 GiB package,
8 GiB cache; no resume of incomplete downloads. `cleanup --candidate CACHE_ID`
removes only explicitly selected unused cache entries. Backups are never removed.
Browser imports use authenticated CSRF-protected 4 MiB chunks (at most 4 GiB),
owner/offset checks and native full validation after completion. At most two
uploaded payloads are retained. The administrator reviews/removes stale inbox
uploads explicitly; nothing deletes histories to free space.

Anonymous GitHub HTTPS is restricted to the configured published Releases and
approved asset redirects. No app/API secret is sent. Timeouts, metadata sizes,
package sizes and retry backoff are bounded. Failure/offline/rate limiting means
**check unavailable**, not up to date. Stable is default; prerelease/development
requires explicit selection. An unavailable discovery source does not affect
monitoring. “Up to date in authenticated index” only describes that current,
fresh index; it does not prove the internet contains no newer release.

A trusted local clock is required. Backward movement over five minutes refuses
validation. Expired/future metadata is refused. For long-offline installations,
independently correct the local clock and carry newly publisher-signed metadata
for the same complete package on USB; do not disable expiry, change clocks to
reuse old metadata, or substitute candidate-supplied keys.

## Concrete approval and installation

Prepare a plan first. Only an authenticated compatible staged candidate with an
exact certified SQL/source/runtime pair can reach installation readiness. Review
version/source, required disk, downtime and retained recovery plan. Then the
browser admin explicitly requests installation. The native worker freezes the
request/actor and plan in protected state; it does **not** execute installation.

The local OS administrator must independently review the request/status and run
using the retained controller:

```text
install --root ROOT --request REQUEST_UUID --confirmation EXACT_PLAN_SHA256 --maintenance-confirmed
```

Alternatively a local administrator may use `--candidate CANDIDATE_SHA256` with
the exact freshly reviewed `plan` confirmation. This is an explicit elevated
operation, never a browser shell command. Revalidation of permissions, actual
schema, host, trust, security, source/runtime and candidate bytes happens at use
and again after writer fencing. A changed decision requires renewed approval.

The operation drains D5A jobs/outbox, stops owned writers, verifies a coherent
D5B safety backup, runs the authenticated target migration against copies with
controlled environment/temp/cache/storage paths, reconciles monotonic security,
and journals old/new program, runtime, configuration and data promotion. It
never resets/seeds, regenerates keys/issuer or changes network mode/IP/DNS.
Mac uses releases/current/shared; Windows fixed paths are switched only while
services are stopped. Locked executables cause a fenced, explicit recovery.

The controller accepts and resumes only after complete verification. Receipt
and outbox identities survive; later retry cannot replay a committed movement.
Clients receive a maintenance/version notice and a user-confirmed refresh. They
never install runtimes/databases/collectors and are not automatically reloaded.

## Failure recovery and trust rotation

Retain `state|shared/recovery/operations/OPERATION_UUID` and its private journal,
safety backup, original program/runtime and staged rollback. Commands use the
**old retained** controller, even if the current app/PHP cannot start:

```text
recover-owner --root ROOT
recover --root ROOT --operation OPERATION_UUID --decision current|forward|rollback
accept --root ROOT --operation OPERATION_UUID
resume --root ROOT --operation OPERATION_UUID
```

`recover-owner` only releases a proven-dead updater lock. For a dead D5A operation
owner, run the retained `app/deploy/lifecycle/maintenance.py recover --root ROOT
--operation OPERATION_UUID` separately. It never reopens writers. Existing live
PIDs are never killed or stolen. Review dead producer jobs using D5A's explicit
review command only when needed.

Use `current` only when the journal proves no data/program promotion began.
Forward/rollback needs the complete recorded program/data plan and hashes. An
interruption during early staging without that plan remains fenced for verified
current recovery where possible; otherwise preserve evidence for administrator
repair. No recovery guesses missing bytes. A failed rollback stays fenced.
Ordinary D5B restore/accept/resume cannot handle D5C update journals. Once writers
may have reopened, in-place rollback is refused to prevent losing newer traffic;
a separate coherent recovery/update decision is required.

`rotate --manifest SIGNED_ROTATION --confirmation ENVELOPE_SHA256` implements
D3's existing-authority rotation with exact approval, scoped public keys, atomic
authoritative catalog writes and durable generations/revocations. Catalog is
canonical; trust/state JSON are recovery mirrors. `repair-mirrors` explicitly
repairs mirrors from a verified catalog after interruption. It cannot bootstrap
missing authority or revive a revoked key. Emergency trust recovery needs a
separate independently authenticated administrator procedure. No automatic root
replacement is supported. Refresh metadata for the new generation explicitly.

## Manual release production

Build all complete platforms from one reviewed clean commit/version through the
manual D3 workflow. Builders include trusted updater contracts/recovery code,
`UPDATE-PATHS.json`, UPDATES.md and update sidecars. Exact approved source+actual
SQL schema/runtime paths belong in `build/updates/upgrade-paths.json` and the D3
compatibility policy only after path certification. They remain empty by default.

Using a protected build machine and external private key, prepare fresh signed
per-platform update manifests, then a signed index:

```sh
python3 -B build/updates/release-index.py --manifest PLATFORM.update.json OTHER_PLATFORM.update.json --sequence NEXT_SEQUENCE --trust-generation CURRENT_GENERATION --notes REVIEWED_NOTES.txt --sign-key /protected/publisher.pem --public-key /verified/publisher-public.pem --output philcst-release-index.v1.json
```

Use that exact filename as an asset of a published `vVERSION` GitHub Release,
alongside complete packages and the named signed sidecars. Signing, manual
publication and installed execution remain separate approvals. This task does
not publish or trigger workflows. Missing signing credentials, native target
acceptance and unapproved origins block production updates. Old D2/D3 candidates
remain their original bytes/identities; they do not contain D4/D5C.
