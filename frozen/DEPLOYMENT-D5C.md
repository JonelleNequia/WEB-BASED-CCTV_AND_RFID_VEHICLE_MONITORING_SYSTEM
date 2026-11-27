# Deployment D5C — Authenticated online and offline updates

27 November 2026, Asia/Manila. Scope: D5C source implementation and isolated
acceptance fixtures. **No production upgrade origin or native installed update
is certified. Stop after D5C and wait for Go Deployment D5D.**

## Implemented behavior and verified prerequisites

D1/runtime inventory, D2, D3/update amendment, D4, D5A, D5B and their actual code
were inspected. No applicable AGENTS.md was found in the checkout or ancestor
locations. Starting HEAD was `c4d5e07c0bc1d879ec93742802a6c51693ecfc22`; the starting
worktree was clean. Existing application, OCR, plate, registration, lost-UID and
D4 transport behavior is retained.

D3 supplied signed per-platform manifest/state/trust and streaming content
validation, but no installed discovery/controller. D5A supplied durable delivery,
writer admission/reservations and owned service orchestration. D5B supplied
same-installation, exact-source recovery and monotonic security reconciliation;
it was **not** a cross-version updater. D5C adds a separate `UpdateRecovery`
controller; ordinary D5B restore/accept/resume explicitly refuses update journals.

Optional preparation uses published Release assets of
`JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM`. Stable is default.
Prerelease/development require explicit selection. Defaults are automatic check
**off**, automatic download **off**, automatic install **absent**. The optional
native minute worker processes admin preparation requests and six-hour scheduled
checks. Installing that worker is an explicit local administrator action.

The anonymous HTTPS transport has certificate verification, approved repository/
asset redirect destinations, four-hop limit, request/read and independent child
wall-clock deadlines, response/download bounds, 15-minute failure backoff (or
bounded Retry-After), and no app credentials/cookies/API keys. Public Releases
response is limited to 50 entries/2 MiB; indices to 1 MiB, five indices/check,
and at most 12 candidate sidecars/check. Checks do not run from login, startup or
ordinary requests. GitHub's documented API version and published-release assets
are used; see [Releases](https://docs.github.com/en/rest/releases/releases) and
[Release assets](https://docs.github.com/en/rest/releases/assets). No release,
workflow or remote service was changed or triggered here.

Offline, failed and rate-limited discovery reports **check unavailable**. “Up to
date in authenticated index” describes only that fresh signed index. An
unreachable repository cannot prove that the installation is the newest.

Download and USB/local import share D3 signature/channel/platform/version/origin,
archive hash/size, content inventory, source/runtime identity and safe path checks.
No candidate code runs during initial validation. Complete packages are limited
to 4 GiB in a separate 8 GiB protected cache, with a free-space reserve. Interrupted
downloads remain `.incomplete-UUID`; resume is not implemented. Import needs no
GitHub request. Complete staged bytes are revalidated before extraction, planning,
and installation. Cache entries are administrator-only; imported browser bytes
remain untrusted until copied/verified there. Cleanup is explicit, cache-only,
refuses the selected candidate, and never removes backups or outbox work.

## Trust, freshness and interfaces

D3 `philcst.update.v1`, public `philcst.update-trust.v1` and
`philcst.installation-update-state.v1` remain the update contracts. TLS issuer
trust remains separate. Publisher keys never come from candidate metadata.
Bootstrap requires independently verified installed source and canonical public
trust-store hashes. Fixture roots are refused in production; ephemeral private
keys are generated outside the checkout and removed. Builders also reject private
key/container payloads. No publisher key or credential was provisioned here.

New contracts:

| Contract | Purpose |
| --- | --- |
| `philcst.release-index.v1` | Signed repository/channel/generation/sequence, fresh release tags, sidecar hashes and escaped release notes |
| `philcst.upgrade-paths.v1` / `UPDATE-PATHS.json` | Exact source/version/platform/schema/runtime/actual SQL origin → exact target/source/build/schema/runtime and certified target SQL schema |
| `philcst.update-control.v1` | Protected authoritative catalog: trust, installed state, preferences, discovery floors, clock floor, candidate, concrete approvals and outcomes |
| `philcst.install-update-plan.v1` | Candidate/host/security/trust/schema-bound confirmation, disk requirement, downtime and retained recovery plan |

Synthetic unsigned index shape (invented values; not installable/trusted):

```json
{"signed":{"schema":"philcst.release-index.v1","product":"philcst-vms",
"repository":"JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM",
"channel":"stable","sequence":1,"trust_generation":1,
"issued_at":"2026-11-27T00:00:00Z","expires_at":"2026-12-27T00:00:00Z",
"releases":[{"tag":"v1.1.0","manifest":"COMPLETE-PLATFORM.zip.update.json",
"manifest_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
"notes":"Synthetic example only."}]},"signatures":[]}
```

The signature is D3 RSA-PKCS1-SHA256 over canonical signed JSON, using an installed
SPKI verifier with channel/platform/role scope, validity and revocation. Unknown,
expired, out-of-scope, revoked, unsigned or tampered metadata fails. A signed index
must extend the protected sequence/body hash, trust generation and known-release
floor. Same-sequence replacement and forgetting known releases fail. Same-version
source replacement, unknown origins and ordinary downgrade remain refused.

The authoritative catalog is atomically replaced before its D3 JSON mirrors;
interrupted mirror writes fail recovery validation until the local administrator
runs `repair-mirrors` from that verified catalog. Rotation requires the existing
rotation authority, exact envelope confirmation, strictly next generation,
preserved revocations and an active remaining update key. Catalog and protected
D5B generation/revocation receipts persist; rollback cannot revive old authority.
A candidate cannot install its own root. Emergency bootstrap/recovery still
requires an independently authenticated administrator procedure.

Metadata must be fresh against the local clock. The catalog records a maximum
observed clock floor and refuses backward movement over five minutes. Long-offline
operators must independently correct their clock and obtain newly signed metadata
for the exact same package on USB. There is no public-NTP dependency, ignored expiry
or clock-bypass switch. Exact commands/trust shapes are in
[UPDATES.md](deploy/updates/UPDATES.md) and the retained
[D3 interface](build/updates/README.md).

## Operator controls and privilege boundary

Settings → Advanced → **Updates** shows version/platform/source, actual check
outcome, compatible release/escaped notes, preparation or validation failure,
readiness, disk/downtime/recovery requirements and installation outcome. It adds
Check, Download, chunked local Import, Prepare installation plan, then Request
installation for the exact prepared candidate. Guard/unauthenticated mutations
are rejected; existing session/account restrictions and CSRF apply.

PHP writes fixed, bounded intents only: no arbitrary shell/URL/server path,
service operation, publisher-root edit or installation from a browser. Import is
owner-bound with CSRF, exact offsets, 4 MiB chunks and free-space checks. At most
20 pending requests and two retained uploaded payloads are allowed. Processed or
invalid intents cannot starve later requests. Abandoned uploads require explicit
local administrator review/removal; they are not silently pruned.

The privileged preparation worker rechecks admin identity, active status,
session version, request expiry and candidate identities, then freezes the plan
and request in protected state. It never installs. After explicit browser
maintenance confirmation, a local OS administrator must review and execute using
the **retained controller interpreter**, for example:

```text
install --root ROOT --request REQUEST_UUID --confirmation EXACT_PLAN_SHA256 --maintenance-confirmed
```

An elevated terminal can also approve an exact freshly prepared candidate directly.
[UPDATES.md](deploy/updates/UPDATES.md) supplies Windows PowerShell 5.1 and Mac
commands for independent controller/trust bootstrap, optional worker setup,
preparation, install, rotation and recovery. No such native setup was run here.

Browser clients receive a same-origin maintenance/session/version notice and a
user-confirmed refresh. Unsaved input is retained until the user chooses refresh;
there is no reload loop and clients install no runtime/database/collector.

## Exact path certification and unsupported installations

The complete synthetic fixture uses this precise test-only path:

- Source `1.0.0`, source SHA `bbbb…` (64), commit `aaaa…` (40),
  `SYNTHETIC-D5B` identity, actual current migration/SQL schema, host PHP **8.5.3**.
- Target `1.1.0`, source SHA `eeee…` (64), commit `dddd…` (40),
  `SYNTHETIC-D5C` identity, one authenticated additive fixture migration creating
  `d5c_fixture_feature`, new `/d5c-fixture-feature` application route and actual
  packaged arm64 PHP **8.4.24**. The marker identities are deliberately synthetic,
  not hashes falsely claimed for a production release. Actual fixture files,
  SQL schema, runtime/content/package hashes are validated separately.
- Windows layout tests use the same synthetic application/schema transition and
  host PHP named as the Windows fixture executable. They simulate file locking
  and layout promotion on Mac; **they do not execute a Windows PHP binary**.
- Exact migration-map/runtime and actual SQL hashes are constructed and checked in
  each isolated fixture. No broad production version range is inferred from this.

The D5B additive `2026_11_20_000001_create_recovery_state` migration was exercised
on a private pre-table database copy and existing table contents were compared.
This proves that additive migration fixture, not an end-to-end pre-D5B adoption.
Pre-D5B installations lack the protected coherent D5B backup/epoch prerequisite;
D5C refuses them until a separately reviewed adoption initializes it safely.

Production `compatibility.json` origins/accepted fingerprints and
`upgrade-paths.json` remain **empty**. D2/D3 unsigned candidates, changed-source
legacy installs, missing D5A/B state, unsupported databases/OS/architecture,
uncertified actual SQL ledgers and cross-machine/path installs cannot be updated
by pretending the synthetic pair certified them. D5D handles transfer separately.

## Journaled installation, rollback and crash behavior

Before maintenance, the controller verifies the host, current immutable program/
runtime inventory, migration ledger, actual SQLite schema, candidate, exact path,
trusted independent recovery tools, permissions and disk. Confirmation binds that
decision. After D5A drain/fence/owned-service stop it revalidates stable security,
trust and compatibility; a material change requires a new approval.

A D5B complete coherent safety set covers DB/receipts/outbox/media, accounts/setup,
gates/calibration/device configuration, APP_KEY/API secrets, TLS issuer/network
ownership, update trust and recovery/security state. Target migrations run only
against authenticated staged code/data with controlled environment, cache,
SQLite/temp/storage paths, file-access restrictions and process functions disabled.
They do not inherit live DB/cache/device/temp overrides, seed/reset or regenerate
secrets. Migration ledger, expected target SQL schema, integrity/foreign-key
baseline, decryption, exact media references and security state are validated.

The update journal extends D5B's private operation directory with prior/target
metadata, old/new program hashes, immutable release fingerprints, per-component
intent/progress, compatible data sets and security epochs. Mac stages a new
release, prepares matching configuration copies and promotes `current`; Windows
journals all incoming immutable root components and preserves mutable state while
switching the fixed app/runtime paths. Incoming ACLs are never authoritative.
Old/new immutable metadata remains usable after files move out of staging.

An independent protected prior app/runtime/controller capsule is retained outside
replaceable program paths, with a root-owned complete tree-hash receipt and stable
Controls entry. Windows recovery uses frozen ACL/service helpers even if app or
runtime replacement is interrupted. It does not overwrite the sole running
updater. Native executable-lock and service/ACL acceptance remains required.

No failure restarts writers from `finally`. A failed operation remains fenced with
protected/public recovery status. Explicit current/forward/rollback recovery uses
recorded hashes; unexpected/missing bytes are not guessed. Early prepared data can
roll back before a program plan exists. Startup interruption re-stops/verifies
owned access services before recovery. Windows rollback recognizes only a verified
mutable-data-only stub after an interrupted app rename, restores prior immutable
code, and clears stale retained SQLite sidecars; unknown immutable bytes still
refuse recovery. Genuine rollback verifies **program,
runtime and reconciled prior data** before acceptance and explicit resume. A
failed rollback remains fenced. Lost UID/security/session/setup and publisher
revocations remain monotonic. Once writers may have reopened, destructive
in-place rollback is refused so later traffic is not discarded.

Dead process locks require explicit PID/owner review; live owners are not stolen.
A failure before the update journal begins is still a D5A maintenance operation:
inspect D5A status/operation, repair the failed prerequisite and use its verified
Resume path, rather than inventing a D5C journal. Backups and old release/capsule
sets remain retained; no automatic sensitive-backup deletion was added.

## Verification actually run

All application DBs, secrets, issuer state, media, storage, OS adapters and publisher
keys were private synthetic fixtures. D5C cross-version migrations and rollback
used real Laravel/PHP and the actual reconciliation/promotion orchestration;
service/ownership adapters simulate OS changes. No fixture accessed cameras or RF.

Final verification results are recorded below after the final run; earlier failed
fixtures were retained in private logs, corrected and rerun. No baseline identity,
security, plate, OCR, permission or lost-UID assertion was weakened.

| Check | Final result |
| --- | --- |
| D5C focused source/current acceptance | **30 distinct tests passed across collected runs**: 29-case run plus the additional Windows missing-app crash/sidecar case; both Windows cases rerun after its fix. Includes five SIGKILL boundaries, real application/schema/runtime transition and rollback. |
| Full isolated Laravel | 895 tests, 9,263 assertions, one existing skip; no failures/errors |
| Admin update Laravel subset | 7 tests, 38 assertions, included in full suite |
| D3 update contracts | 29 passed |
| D5A delivery/barriers | 31 passed |
| D5B recovery/security | 28 passed |
| Shared D2 package tests | 14 passed after adding required new verifier/path fixture files |
| Mac packaging policy | 19 passed |
| D4 network/stream policies | 17 passed, including disposable real Caddy/PHP mode/CA fixture |
| PowerShell pure helpers | Common 17, preflight 13, lifecycle 30, recovery 9, client/ownership 22 passed on Mac PowerShell 7 |
| PowerShell parser | 35 scripts parsed, no syntax failures |
| JS tests | 17 passed |
| Chrome update/import/version DOM fixture | 13 checks passed, including maintenance/disconnection/session feedback without losing input |
| Chrome actual rendered Updates page | 12 checks passed at desktop/390px, escaped notes and confirmation/layout |
| Chrome existing Registry / list reports | 83 / 64 checks passed |
| Private frontend build | Vite production build passed |
| Manual release-index signing CLI | Ephemeral publisher signature verified by real PHP; mismatched public/private signer refused without output |

The Laravel skip is `StartupChecksTest::test_laravel_and_the_detector_work_out_the_same_code_version`:
the private detector venv is absent. Physical detector/RFID inference was not a
D5C test. A regression first reproduced the upload-slot bypass before any chunks arrived; serialized reservations now reject a third import. An early full-suite copy lacked the existing go2rtc ZIP and forced
managed-service defaults; the private fixture was corrected and the full rerun
passed. An additional Windows SIGKILL fixture reproduced the missing-active-app rollback gap; it now verifies prior program/data restoration and stale-sidecar removal. The preliminary Windows lock test initially targeted unchanged identical
runtime bytes and therefore injected no fault; it was corrected to lock a program
component that actually moves, then rerun. Process-kill tests use SIGKILL in
private subprocesses; they are not a power-loss/reboot certification.

NOT RUN: actual native Windows/PS5.1/.NET/SID/ACL/task/locked-EXE behavior; native
Intel runtime; privileged Mac installation/LaunchDaemons/capsule ownership;
minimum/current OS matrix; physical power-loss/reboot/reinstall; clean offline
machine; installed background worker or production broker; actual GitHub live
discovery/download; two real clients/trust stores; camera/video/RFID hardware;
production publisher signing/root provisioning/notarization and native update
execution. Other browser suites were not rerun; the previously documented
classification-ui fixture mismatch remains outside D5C. Existing D3/D4 maintenance, signing and redistribution gates remain.

## Packaging, artifacts and preservation

Builders now include updater/recovery/verifier code and UPDATES.md, freeze exact
UPDATE-PATHS after BUILD-INFO without recursive identity, and retain D3 platform
sidecars/common-source matrix. `release-index.py` creates signed index metadata
only, binds common source/build identity within a version and verifies the
external signing key matches its selected public key. Building, manual publication
and installed execution remain separate. No remote workflow trigger/push/publish
step was added or invoked.

No complete Windows/Intel/arm64 D5C runtime archive was assembled. Native Windows
assembly/acceptance and production signing/approved origins are unavailable, and
the original pinned multi-GiB build cache is absent. A complete native runtime
rebuild would require reacquiring supplier inputs and simultaneous staging,
archive/extraction plus acceptance space; roughly 17 GiB remained while fixture
work ran. Old candidates/user data were not deleted to force a rebuild. A bounded
current-source review artifact is produced separately and is explicitly **not**
an installer or update package. Its final paths/hashes are recorded below.

The existing Windows D2 ZIP and Mac D3 tar.gz archives retain their original
identities. They do not contain D4/D5C, cannot be relabeled current, and are not a
same-source release matrix. D6 must rebuild all platforms from one reviewed clean
commit/version with trusted signing and path-specific native acceptance.

No live installation, migration, restore, account/device/service/scheduling,
hosts/firewall/DNS/CA trust or network-mode change was performed. The working .env, staged index, base commit and unrelated starting source passed
the preservation audit; no unexpected baseline file changes were found. Original
D2/D3 archive byte sizes and all three SHA-256 values were rechecked unchanged. The active
working database is not a fixture and was not restored/overwritten; ongoing
external services may change it, so no byte-for-byte DB preservation is claimed.

## Final source-review identity (post-build appendix)

This ZIP is a **source review artifact**, not a complete platform installer,
signed update or native acceptance result. Earlier local review drafts are
superseded. ZIP CRC and every frozen source-file SHA-256 were verified.

- Path: `build/updates/dist/D5C-source-review-final/PHILCST-VMS-D5C-SOURCE-REVIEW-dev.dc3d0335019f.zip`.
- Version: `0.1.0-d5c-review-dev.dc3d0335019f`; build identity `D5C-source-review-20261127`.
- Base commit: `c4d5e07c0bc1d879ec93742802a6c51693ecfc22`; **841** frozen source files.
- Canonical source SHA-256: `dc3d0335019fa19b38d8ce3c95aeddf131852b9ff408c85429d455dcb4f746e4`.
- ZIP size: **2801482 bytes**; SHA-256: `c27293d0498260e911cff5a76b89b5e91d14909e6f9acbb841c7378147c7d263`.
- `SOURCE-INFO.json` SHA-256: `7dc2ac747ae07ff28775aff245c8d2ffc188a198f87e6fa7dfdaa54cf9ad518d`.
- Identity file: `build/updates/dist/D5C-source-review-final/SOURCE-REVIEW.json`;
  archive checksum: same ZIP filename plus `.sha256`.

This identity appendix was added after freezing/building the source. It does not
recursively alter the archive's manifest; all implementation/build/test files
match the frozen source. A later clean committed/native build necessarily has a
new identity. The archive contains the preceding report without this appendix.
No old D2/D3 identity was rewritten and no complete D5C runtime set is asserted.

## Complete changed files and schema/configuration

There are **no new application migrations** and no live schema changes. New
state is filesystem metadata under protected `state|shared/update`,
`update-controller`, `update-cache`, derived `update-status.json`, fixed untrusted
`storage/app/update-inbox`, and existing D5A/B lifecycle/recovery paths. An optional
owned preparation task/LaunchDaemon is created only by explicit native setup.
The existing D4 CA/configuration/firewall/mapping contracts are retained.

```text
DEPLOYMENT-D5C.md
app/Http/Controllers/DeploymentVersionController.php
app/Http/Controllers/SettingsController.php
app/Http/Controllers/UpdateController.php
app/Services/UpdateRequests.php
build/macos/build.py
build/updates/paths.py
build/updates/release-index.py
build/updates/upgrade-paths.json
build/windows/assemble.ps1
build/windows/package.py
deploy/lifecycle/coordination.py
deploy/lifecycle/maintenance.py
deploy/lifecycle/recovery.py
deploy/macos/update.command
deploy/updates/UPDATES.md
deploy/updates/cli.py
deploy/updates/contracts.py
deploy/updates/install.py
deploy/updates/manager.py
deploy/updates/native.py
deploy/updates/store.py
deploy/updates/transport.py
deploy/windows/lifecycle-adapter.ps1
deploy/windows/update-program-acl.ps1
deploy/windows/update-worker.ps1
deploy/windows/update.ps1
public/js/deployment-refresh.js
public/js/updates.js
resources/views/layouts/app.blade.php
resources/views/settings/index.blade.php
resources/views/settings/tabs/updates.blade.php
routes/web.php
tests/Feature/UpdatesTest.php
tests/browser/updates-page-ui.mjs
tests/browser/updates-ui.mjs
tests/deployment/test_package.py
tests/fixtures/updates-page.php
tests/updater/fixture.py
tests/updater/test_broker.py
tests/updater/test_build_contracts.py
tests/updater/test_install.py
tests/updater/test_manager.py
```

## HANDOFF FOR D5D AND D6

**D5D:** Keep transfer separate from this same-host updater. Preserve exact
occurrences, receipt/outbox identities, DB/accounts/setup, permanent lost UIDs,
APP_KEY/API secret, historical/private media, assignments/calibration, recovery
security/epochs, update publisher trust/floors/clock state and the separate D4 TLS
issuer/network ownership receipts. Define one active server owner/epoch and
coherent old/new handoff; deliberately change DNS/owned mappings/client trust
only through its authorized transfer workflow. Do not clone collectors or revive
stale authority. No cross-PC recovery/adoption is certified by D5C.

**D6:** Certify concrete production source→target/schema/runtime pairs, including
legacy adoption and pre-D5B prerequisites where approved. Rebuild one same-source
three-platform set, provision authenticated publisher material through protected
installers, sign/notarize and execute native Windows5.1/Intel/arm64 minimum/current
OS, locked-executable/self-update, service ownership, worker stop/restart,
readiness, process/power-loss, rollback, disk/permissions, client refresh and
long-offline freshness/rotation/revocation tests. Preserve tested exact-path
allowlists; do not convert one fixture success into broad version ranges. Close
existing D3 dependency/redistribution and D4 live-transport gates. Retain verified
capsules/backups until institutional acceptance and an explicit retention policy.

Private evidence pointer: `/tmp/philcst-deployment-d5c-workspace.txt`. These logs,
JUnit, synthetic keys/data and fixtures are local test evidence, not installation
backups, production trust roots or deployable releases.

**Stop after D5C. Wait for “Go Deployment D5D.”**
