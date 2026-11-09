# Deployment D4 — Offline named HTTPS and browser clients

Completed source/fixture work: **9 November 2026, Asia/Manila**. Canonical address:
**https://philcst.vms.internal**. This is a development implementation candidate, **not a
certified native installation or a deployed release**. Stop after D4; D5 remains unauthorized.

The D4 attachment, D1 and runtime inventory, D2, complete D3/update amendment, actual
installers/services, collectors, media writers/references and authorization paths were
inspected before implementation. No applicable AGENTS.md was found in the checkout or
ancestor directories. Prior application and packaging work was retained.

## Implemented behavior and architecture

Fresh packages default to **This computer only**. An administrator can explicitly select
**Share on local network**, using an already configured stable private IPv4 and interface.
Both modes keep the same canonical HTTPS origin. No OS rename, `.local`, public DNS/ACME,
automatic router/IP change, background updater or second client-side collector is involved.

```text
Browser on main computer       Windows/Mac browser clients
canonical name -> 127.0.0.1     school DNS or owned hosts -> selected server IPv4
             \                 /
             Caddy HTTPS :443 + per-install local issuer
               | authenticated same-origin pages/media/signaling
               +-- loopback PHP FastCGI
               +-- loopback go2rtc control / detector proxy
               +-- negotiated encrypted WebRTC :8555

Main computer collectors -> key-protected HTTP 127.0.0.1:8080 -> application
Only the main computer owns the database, device sessions and occurrence reconciliation.
```

`deploy/network/policy.py` validates exact configuration fields, the real selected NIC,
RFC1918 IPv4, a usable host address and an explicit remote scope within the NIC subnet.
IPv6 sharing is deliberately unsupported; contradictory A/AAAA/hosts aliases cause a
refusal. Local mode binds browser/media listeners to loopback and removes owned LAN
exposure. LAN mode binds HTTPS to loopback plus the selected IPv4 and WebRTC to the selected
IPv4. The main computer's own managed name mapping remains loopback in both modes.

Preflight checks ports, hosts conflicts and exposure ownership before fresh provisioning.
It never kills unrelated listeners or chooses another browser port. Windows fresh-install
preflight uses the adapter from the verified staging payload, before files exist under the
destination root. Network changes validate Caddy configuration, retain prior config and
ordinary-error rollback, briefly stop/restart web/PHP/live relay, and check HTTP and pinned
HTTPS readiness. A failed rollback reports administrator recovery and leaves public services
stopped. This is **not** a power-loss-safe D5 transaction. Collectors remain independent of
browsers; a mode reload briefly interrupts integration, so use a quiet period until D5
provides durable delivery/fencing. No database/device/account reset occurs in this control.

## Server installation and mode selection

Exact package-contained instructions: [NETWORK-SETUP.md](deploy/network/NETWORK-SETUP.md).
Use a newly built and externally verified D4 package on a disposable acceptance target.
**Do not copy this source over an existing D2/D3 installation.** Changed-source upgrades,
backup/restore and interrupted-install recovery remain D5 work.

Windows x64, elevated PowerShell, isolated unsigned acceptance target only:

```powershell
& 'STAGING\PHILCST-VMS\app\deploy\windows\install-package.ps1' `
  -Payload 'STAGING\PHILCST-VMS' -Root 'C:\PHILCST-VMS' -AllowDevelopmentCandidate
$root='C:\PHILCST-VMS'
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" status --root $root
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" trust-root --root $root --fingerprint '64_HEX_SHA256'
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" export-root --root $root --output 'E:\PHILCST-public-root.crt'
```

Intel or Apple Silicon Mac, using the corresponding native package:

```sh
./Install.command --allow-development
ROOT='/Library/Application Support/PHILCST-VMS'
PY="$ROOT/current/runtime/python/bin/python3"
CTL="$ROOT/current/app/deploy/network/server.py"
sudo "$PY" -B "$CTL" status --root "$ROOT"
sudo "$PY" -B "$CTL" trust-root --root "$ROOT" --fingerprint 64_HEX_SHA256
sudo "$PY" -B "$CTL" export-root --root "$ROOT" --output /Volumes/USB/PHILCST-public-root.crt
```

Read the installation-local root fingerprint independently before explicit trust enrollment.
Open the installed Open shortcut/canonical URL and complete the existing protected personal
first-admin setup. No demo credentials are generated. Existing accounts/keys/setup state
stay within the existing same-source reinstall restrictions.

Have the network administrator reserve an address with DHCP or separately configure static
addressing. Create a protected configuration outside immutable releases, adapting this example
to the real address/interface (for example `en0` on Mac):

```json
{"schema":1,"mode":"lan","address":"192.168.44.10","interface":"Ethernet","remote_scope":"192.168.44.0/24"}
```

Apply explicitly using the installed bundled interpreter/helper:

```powershell
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" configure --root $root --config 'C:\Admin\philcst-lan.json'
```

```sh
sudo "$PY" -B "$CTL" configure --root "$ROOT" --config /path/to/philcst-lan.json
```

To return to local-only, apply a file with this exact configuration:

```json
{"schema":1,"mode":"local","address":null,"interface":null,"remote_scope":null}
```

Only `# BEGIN PHILCST-VMS-D4` / `# END PHILCST-VMS-D4` hosts entries are replaced/removed;
unrelated entries and line endings are preserved by server/Windows planners. Unowned aliases,
duplicate or altered blocks fail. Mac shell helpers preserve unrelated lines; built-in text
tools may normalize a missing final newline. School DNS is preferred where administered;
client DNS mode requires the canonical name to resolve exclusively to the chosen IPv4.

## Browser-client enrollment, repair and removal

Full instructions: [CLIENT-CONNECTION.md](deploy/clients/CLIENT-CONNECTION.md). The small
client bundles contain only platform helpers, instructions and manifests. No PHP/Python,
database, device configuration, server or monitoring service is installed on a client.

Privately deliver the **public** installation root and its SHA-256 fingerprint through
independent administrator/USB handoff. Helpers verify selected address, root identity,
certificate time/name/chain and HTTPS readiness **before** adding trust/mappings. No TLS
warning bypass, insecure curl flag or global certificate-validation override is used.

Windows PowerShell **as Administrator**, compatible source targeting 5.1:

```powershell
.\Connect-to-PHILCST.ps1 -Action Connect -ServerAddress 192.168.44.10 `
  -RootCertificate 'E:\PHILCST-public-root.crt' -RootFingerprint '64_HEX_SHA256'
.\Connect-to-PHILCST.ps1 -Action Status
.\Connect-to-PHILCST.ps1 -Action Repair
.\Connect-to-PHILCST.ps1 -Action Remove
```

Add `-NameResolution DNS` for a verified school-DNS mapping. Windows checks the per-connection
chain against the independently pinned root; an untrusted-root result is allowed only for
that selected root, with name/time/other chain failures rejected. The offline issuer has
no public revocation service. Shared desktop shortcut: `Open PHILCST Server.url`.

Mac Intel/Apple Silicon, built-in shell/security/curl/openssl only:

```sh
sudo ./Connect-to-PHILCST.command --action connect --server 192.168.44.10 \
  --root /Volumes/USB/PHILCST-public-root.crt --fingerprint 64_HEX_SHA256
./Connect-to-PHILCST.command --action status
sudo ./Connect-to-PHILCST.command --action repair
sudo ./Connect-to-PHILCST.command --action remove
```

Add `--dns` for verified school DNS. Shortcut: `/Users/Shared/Open PHILCST Server.webloc`.
System Keychain enrollment is constrained to SSL and the canonical hostname. A preexisting
unowned root with conflicting trust is refused rather than overwritten. Helpers record
root/mapping/shortcut additions in administrator-owned client state; repeated enrollment is
idempotent and removal retains borrowed roots/unrelated mappings and altered shortcuts.
An expired owned Mac root can be removed by its verified recorded identity; it cannot be
used for new enrollment. Ordinary enrollment failures restore saved state. Native fault
acceptance and crash-safe recovery remain unverified.

Restart browsers after initial trust enrollment. Edge/Chrome/Safari use their relevant OS
trust integration; actual native trust acceptance is still required. Firefox enterprise-root
settings/policy are documented separately; the helper does not silently alter Firefox/NSS
profiles. See [Mozilla's guidance](https://support.mozilla.org/en-US/kb/setting-certificate-authorities-firefox)
and [Apple's trust controls](https://support.apple.com/guide/keychain-access/change-the-trust-settings-of-a-certificate-kyca11871/mac).

## Issuer lifecycle, service identities and allowed transports

Caddy **2.11.7** uses an installation-specific internal issuer, generated only at explicit
fresh installation/configuration. `skip_install_trust` disables implicit service-side trust
changes; no public ACME or DNS challenge is configured. Durable private state is
`ROOT/state/web/tls` on Windows and `ROOT/shared/web/tls` on Mac. Root identity is recorded
outside the replaceable payload. Subsequent configuration explicitly pins existing root
certificate/key paths. Missing/incomplete issuer state, changed root identity, expired root
or clock errors refuse configuration; nothing silently regenerates the CA.

The public handoff is checked against that same persistent issuer before export or trust.
Only a new public-certificate output file outside the installation is allowed. No key, APP_KEY,
API secret or camera login is exported. Status reports public certificate validity/expiry,
UTC clock and pinned HTTPS readiness. Offline renewal of an expired fixture leaf was observed
with the same pinned root. Root rotation requires a separate verified administrator process
and client re-enrollment; it is not automated. TLS keys are separate from D3 update-signing
trust. See [Caddy local HTTPS](https://caddyserver.com/docs/automatic-https) and
[Caddy global options](https://caddyserver.com/docs/caddyfile/options).

An unprivileged bind probe on this Mac returned EACCES for port 443. Therefore only Caddy is
newly root-run on Mac; PHP/FPM, detector, go2rtc and scheduler retain `_philcstvms`. Devices
retains its existing root adapter-address workaround. Private web/issuer state is root-only;
the shared parent and identity receipts are protected against app-user replacement.
Windows Caddy uses its own `NT SERVICE\PHILCST-Web` virtual identity; other application
services retain LocalService and Devices its prior LocalSystem identity. Its private issuer
island excludes LocalService. These ownership/ACL changes require native installation acceptance.
Both service environments keep Caddy profile/autosave/cache writes in the protected web
island. Mac Caddy supervisor and launchd output also stay there, outside app-writable logs.

| Port/transport | Scope and purpose |
| --- | --- |
| TCP 443 | Canonical HTTPS; loopback or loopback + selected LAN IPv4; Caddy remote-subnet check |
| TCP/UDP 8555 | Encrypted negotiated WebRTC, selected IPv4 or loopback; no public STUN/TURN |
| TCP 8080 | Loopback collector integration, auth subrequest and health only; API key still required |
| TCP 9001 Mac / 9001–9004 Windows | Loopback FastCGI only |
| TCP 1984 / 8765 | Loopback go2rtc control / detector only |
| TCP 80, UDP 443, RTSP relay | No listener enabled by this configuration |

Windows adds exactly the three owned program/interface/local-IP/subnet rules for TCP443,
TCP8555 and UDP8555, restricted to Private/Domain profiles. It never changes a network
profile or disables a firewall. Returning local removes only those owned rules. Existing
administrator-created broad rules require a native exposure audit.

Mac only adds missing Caddy/go2rtc application-firewall entries when that firewall is enabled;
it removes only recorded additions. Block All or an existing block requires explicit review.
No global PF change is made. The application firewall **does not supply per-subnet packet
isolation**: HTTPS has a Caddy subnet check; WebRTC relies on selected binding, authenticated
signaling and encrypted negotiated sessions. Do not describe this as a Mac subnet firewall.

## Application origin, private media and bounded stream revocation

Named-HTTPS enforcement is enabled by packaged configuration; ordinary development keeps
its existing origin unless explicitly configured. The app forces canonical generated URLs,
Secure/HttpOnly/SameSite=Lax cookies and rejects arbitrary Host/plain HTTP/wrong port/spoofed
forwarded headers. No broad proxy trust is enabled in named mode. Public integration routes
are unavailable; loopback8080 allows only their existing narrow paths plus health/auth.
Development keyless fallback is disabled in named mode. Collectors retain key-protected
loopback HTTP, without insecure HTTPS verification or browser-exposed secrets.

Historical snapshots/crops referenced by VehicleEvent, VisitorRecord, VehicleCrossing or
GuestVehicleObservation retain their stored paths. `/storage/...` compatibility serving
requires existing authentication/account/session checks, an **exact stored image reference**
and a real file contained in the historical public disk. New UUID occurrence media keeps
its exact-reference checks. No plate-based ownership inference or history rewrite is used.
Traversal, unreferenced files, executable extensions and outside-root symlinks fail.
Compatibility supports the existing image-path convention (JPEG/PNG/WebP); unusual legacy
paths outside that convention need separate investigation, not guessed ownership.

Generic Laravel local-disk serving is disabled because its `/storage/{path}` route shadowed
the authenticated compatibility route. Caddy forces media through PHP before any static
handler, including case/slash variants and an existing public/storage link. Binary responses
are explicitly private/no-store: Symfony's file response otherwise replaced the intended
private cache flag. Authorized report images use the same protected origin; Save as PDF
remains browser Print, with no new server PDF renderer.

HLS playlists/segments reauthenticate; playlists reject remote/credential/traversal links.
Detector MJPEG/overlays use the same-origin authenticated proxy; `MJPEG_STREAM_BIND_HOST`
remains loopback. WebRTC uses same-origin signaling and the actual `webrtc_host` bind hook,
with selected candidates and public ICE servers removed on both sides. Raw control APIs
are unavailable to clients. Packaged signaling accepts viewer offers, not producer offers.

Upstream go2rtc 1.9.14 has no output-consumer lease. D4 builds the pinned derivative
**1.9.14-philcst.lease1**, adding server-enforced 30-second viewer closure to both output
signaling paths. See [source/build notes](build/go2rtc/README.md) and the
[version-specific upstream server](https://github.com/AlexxIT/go2rtc/blob/v1.9.14/internal/webrtc/server.go).
The browser renews at 25 seconds and polls authorization every 10 seconds; unauthorized
responses stop/clear playback and pending starts cannot reopen it. Those browser checks
are supplementary: a modified browser still loses its leased server peer.

Packaged detector MJPEG also ends after 30 seconds, checking while frames are absent and
using a two-second socket write timeout. Timer scheduling/wait/write latency can add a small
delay; already delivered frames/browser buffers cannot be recalled. New requests fail after
logout, account disable or stale-session revocation. The compiled two-peer timer fixture
closed both synthetic peers after **30.004415125 seconds** without browser cooperation.
This is not a measurement of live camera/decoder latency or loaded network revocation.

## Verification actually performed

All fixtures used private databases, storage, secrets, issuer directories and OS adapters.
No real hosts, trust store, firewall, user/group, service, camera or reader was modified.
High-port loopback listeners were disposable subprocesses, not installed system services.

| Check | Result |
| --- | --- |
| Full isolated Laravel | **825 tests, 8,756 assertions passed**, host PHP 8.5.3 |
| Focused HTTPS/live/package Laravel | **27 tests, 232 assertions passed** (included in full suite) |
| Final Windows Laravel regression after service-environment edits | **32 tests, 290 assertions passed** in the private copy |
| Network policy + detector-stream fixtures | **17 passed**: modes/NIC/scope, hosts/idempotence/conflicts, secrets retained, rollback, CA persistence/missing-key refusal, fresh Windows adapter path, public-root mismatch, detector timeout |
| Actual bundled native Caddy/PHP certificates | Canonical SNI/root verification, wrong-root/name refusal, auth/integration boundaries and static bypass checks passed at high loopback ports |
| Offline leaf lifecycle | Expired same-key fixture leaf renewed with persistent pinned root; no public ACME configuration; WAN was not physically disconnected |
| Client TLS/policy/ownership | **25 checks passed** with actual Caddy and PowerShell7 on Mac; Mac shell root/wrong fingerprint and expired owned-root removal identity checks passed; no OS trust mutation |
| Shared D2 package / Mac policy | **14 + 19 passed** |
| D3 update contracts | **29 passed**; trust/manifest interfaces retained |
| Windows common / preflight helpers | **17 + 13 passed**, pure helpers on macOS PowerShell7 |
| PowerShell parsing | **28 source/test scripts** parsed without syntax errors; this does not certify PowerShell5.1 native behavior |
| JavaScript unit tests | **17 passed** |
| Isolated Chrome DOM/browser regressions | Registry status/owner **83**, optional RFID **43**, Activity plate images **58**, list reports **64**, live-access DOM fixture **8** checks passed |
| Frontend | Private-copy production Vite build passed |
| Relay derivative | All three targets compiled; arm64 two native peer-timer tests passed; Intel/Windows structural checks only |
| Syntax/boundaries | Python AST, shell syntax, PHP certificate helper and live JS syntax passed; client ZIP contents/hash verification passed |

Concrete regressions fixed during verification: generic storage-route shadowing; BinaryFileResponse
public-cache override; Caddy directive ordering around protected routes; auth-subrequest Host;
owned firewall-name array construction; staged Windows preflight adapter location; public
handoff issuer binding; safe removal of an expired owned Mac root. Existing test expectations
were updated where HTTPS/private serving intentionally replaces the older package contract;
authorization, plate, Review, occupancy and lost-UID tests were not relaxed.

Tests cover canonical setup/personal login, existing setup closure, secure cookies, ordinary
pages/reports/assets, key-protected integration, authenticated historical/new images,
disabled/stale/restricted/logged-out access and HLS/SDP restrictions. Browser regressions
cover existing filters/report images and playback-stop behavior using isolated HTTP fixtures.
**Chrome trusted HTTPS through the machine trust store was not tested.** Caddy routing tests
use a fake HTTP application upstream; Laravel tests separately exercise real controllers.

| Native acceptance requirement | Status |
| --- | --- |
| Windows main server, PS5.1/.NET, service SID/ACL/firewall, actual443 install | **NOT RUN** |
| Mac main server, sudo/LaunchDaemons/ALF/Keychain, actual443 install | **NOT RUN** |
| Intel runtime execution, minimum macOS14 and clean target without dev tools | **NOT RUN** |
| Windows Edge/Chrome and Mac Safari/Chrome/Firefox trust after enrollment | **NOT RUN** |
| WAN unplugged, cold caches, canonical name on two simultaneous real clients | **NOT RUN** |
| Multi-PC actions/filters/Print/CSV/PDF/images and raw-port probes | **NOT RUN** |
| Camera RTSP/H264, representative WebRTC/HLS/MJPEG fallback, physical RFID | **NOT RUN** |
| Loaded live-media logout/disable timing and buffered-frame behavior | **NOT RUN** |
| Reboot/logged-out persistence, same-source system reinstall, LAN-to-local isolation | **NOT RUN** |
| Privileged enrollment/removal/fault recovery on real client stores | **NOT RUN** |

The old classification-ui fixture mismatch recorded by D1/D2 is outside this phase; it is
not claimed fixed. D3 signing/notarization, Intel/PHP maintenance and redistribution gates
also remain. No native installation or full offline release acceptance is inferred from
passing source, high-port TLS, cross-compilation or browser DOM fixtures.

## Complete changed-file list for D4

Relative to the captured D4 starting workspace, including previously untracked packaging
files that already existed. Earlier unrelated changes remain. No new migrations.

Modified existing source:

```text
.gitignore
app/Http/Controllers/LiveViewController.php
app/Http/Controllers/OccurrenceMediaController.php
app/Providers/AppServiceProvider.php
app/Services/DetectorRuntimeService.php
app/Services/Go2rtcService.php
app/Services/SettingsService.php
app/Support/OccurrenceMedia.php
bootstrap/app.php
build/macos/OFFLINE-INSTALL.md
build/macos/build.py
build/macos/templates/Caddyfile.template
build/macos/templates/env.template
build/windows/OFFLINE-INSTALL.md
build/windows/assemble.ps1
build/windows/ci/install-check.ps1
build/windows/package.py
build/windows/templates/Caddyfile.template
build/windows/templates/env.template
config/filesystems.php
deploy/macos/control.py
deploy/macos/service_runner.py
deploy/windows/gate-kiosk-shortcut.ps1
deploy/windows/install-package.ps1
deploy/windows/install.ps1
deploy/windows/lib/common.ps1
deploy/windows/lib/preflight.ps1
deploy/windows/status.ps1
public/js/live-video.js
resources/views/components/live-video.blade.php
routes/web.php
school-vehicle-monitoring-detector/detector_service.py
tests/Feature/RegistrationSnapshotsTest.php
tests/Feature/WindowsKitBundleTest.php
tests/macos/test_offline_package.py
tests/windows/common.tests.ps1
```

Added source:

```text
DEPLOYMENT-D4.md
app/Http/Controllers/HistoricalMediaController.php
app/Http/Middleware/EnforceDeploymentOrigin.php
build/clients/build.py
build/go2rtc/README.md
build/go2rtc/build.py
build/go2rtc/inputs.json
build/go2rtc/viewer-lease.patch
build/go2rtc/viewer_lease_test.go
config/deployment.php
deploy/clients/CLIENT-CONNECTION.md
deploy/clients/Connect-to-PHILCST.command
deploy/clients/Connect-to-PHILCST.ps1
deploy/clients/client-common.ps1
deploy/clients/client-common.sh
deploy/network/NETWORK-SETUP.md
deploy/network/certificate.php
deploy/network/lan.example.json
deploy/network/local.json
deploy/network/policy.py
deploy/network/server.py
deploy/windows/lib/network-policy.ps1
deploy/windows/network-adapter.ps1
tests/Feature/DeploymentHttpsTest.php
tests/browser/live-access-ui.mjs
tests/network/caddy_offline.py
tests/network/test_policy.py
tests/network/test_stream_lease.py
tests/windows/client.tests.ps1
```

## Package/source validity and built outputs

Base commit remains `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`; D4 is an uncommitted
development working snapshot. No workflow was triggered/changed remotely, release published,
commit created or index staged. Both complete builders now use the pinned leased relay,
include the network controls/client instructions, and produce new identities/update sidecars
through the existing D3 mechanisms. The native Go toolchain is build-only. Full package
runtime fingerprints change; **the existing archives do not contain D4**.

The old candidates were preserved, with these exact SHA-256 identities:

| Archive | Bytes | SHA-256 |
| --- | ---: | --- |
| Windows D2 `0.1.0-d2.1-dev.092593dd1048` ZIP | 576,413,086 | `4153ad831042640144f8909177de4bf0fd5dcafcb22b608121b42e67850552dc` |
| Mac arm64 D3 `0.1.0-d3.1-dev.66c18ba2ecda` tar.gz | 497,589,601 | `01cdbcdf56d24d8ed843b401e3fba5f097411737be56aa2fb700dee797677a80` |
| Mac x86_64 D3 same version tar.gz | 618,013,482 | `e92ea889aa1222ccb0b54bdf1a046c4cff0ea46821851f81e302bb668861de95` |

Their full paths/source identities remain in D2/D3. Windows source is
`092593dd104894ad8d0236c292b3129eb3590e8998484248d47f11674041a4d5`; both Macs retain
`66c18ba2ecda68cb7d3e4464d8e24cd54acbfb7dde44068e7845c4cf06148fc9` / `D3-local-20261108`.
They remain original development candidates with original limitations, **stale for D4**;
they cannot be combined into one same-source release matrix or relabeled update-ready.

Complete Windows/Mac D4 archives were **not rebuilt**: only about **3.3 GiB** was available
at final checks, insufficient to safely stage/archive/extract complete candidates while
preserving existing packages. Windows native assembly is also unavailable. No prior archive
or user data was deleted to make space. After source approval, rebuild all three complete
platforms from one reviewed commit/version using the manual D3 pipeline and repeat native
acceptance. No amended-source identity is asserted for an old archive.

Small client-only candidates were built and ZIP contents reverified under
`build/clients/dist/D4-final/`:

| Candidate | Bytes | SHA-256 |
| --- | ---: | --- |
| `Connect-to-PHILCST-Windows-D4-dev.ba4c3be08ba7.zip` | 9,808 | `1500618a7e2901c51faa006743f78648beda9e684843623237c1035e53ff590e` |
| `Connect-to-PHILCST-Mac-D4-dev.ba4c3be08ba7.zip` | 7,971 | `fddc8b40e83a455f5584be1b8568fdb863bd1a07969d7e86c93728ab8c56caa7` |

Common helper-source SHA-256:
`ba4c3be08ba7ded412fe0eefc575ac8b0bf3e3dfafe53e39398341149d2c71d2`.
This hashes the helper file set, **not the complete application source**. Each ZIP includes
HELPER-INFO/content inventory and a checksum sidecar. These are unsigned development helpers,
not authenticated production distribution. Earlier local D4 helper drafts are superseded.

All three relay binaries were compiled from upstream 1.9.14 commit
`b5948cfb25404cc5cb37b166ecaa2dca20b11d4b` and the pinned D4 lease patch
`ee2c833264abf5761803b8a3c34858f4c22667219235bce3d3759849c0cc3ef6`:

| Binary | SHA-256 | Runtime result |
| --- | --- | --- |
| Darwin arm64 | `77a02051a8f446dc2563485f6f466d67f0bedb5c75db4576f5afb419dca6708d` | Native timer tests passed; Mach-O minimum13, full package still minimum14 |
| Darwin amd64 | `ba51ac724f80ff08aaeff7c0aa8e86b995ffd0d3a02b83a7cb195be7c3314901` | Compiled/structure inspected; native execution NOT RUN |
| Windows amd64 | `9e95a14e7d4fa6cd6c09497950f2d699c9ab62fac6fed8da6fe58f793ed0edac` | Compiled/PE x64 inspected; native execution NOT RUN |

Their source metadata/notices and hashes are in the private evidence workspace. Compilation
does not establish live video performance, publisher authentication or target acceptance.
No standalone relay is offered as an in-place upgrade to the running installation.

## Preservation, limitations and D5 handoff

The working `.env`, staged index and unrelated captured source are unchanged. A preexisting
`artisan serve` and device-service process remained running; the working SQLite file changed
while those services were active. They were never stopped or used as test targets. Therefore
**byte-for-byte working-DB preservation cannot be asserted**. Tests used only private DBs;
no live migration/reset/repair or event mutation was intentionally performed, and that active
database was not restored or overwritten. No physical-device or OS configuration changes,
production trust enrollment or deployment were performed.

Remaining release gates include native service identity/ACL/Keychain/firewall acceptance,
minimum/current OS and Windows5.1 coverage, native browser trust, two-PC disconnected tests,
actual video transport/fallback/revocation, signing/notarization and existing D3 dependency/
redistribution decisions. The Mac root Caddy decision needs native service/security review.
The leased relay is now a maintained derivative and must be included in license/source and
security review; never substitute stock go2rtc while claiming server-enforced revocation.

D5 must:

- Preserve the entire private TLS issuer, root-identity/public handoff, network config,
  ownership receipts and protected verifier/update trust **as separate trust systems**.
  Never repair missing keys by regeneration; define explicit authenticated root rotation,
  client re-enrollment and independently verified emergency recovery.
- Preserve APP_KEY/API secrets, DB/accounts/setup, historical/private media, gate names,
  assignments/calibration, exact occurrence links and monotonic lost-UID/security state.
- Implement the D3 update checklist: optional bounded discovery/downloads and offline import,
  trusted signatures/channel/version/compatibility checks, then authorized admin confirmation
  of a concrete update/recovery plan. D4 adds no background updater or Update button.
- Fence/drain **all** writers, scheduler and collectors; provide coherent tested backups,
  migrations on copies, validated schema/integrity/decryption/media and atomic promotion.
  Include TLS/receipt ACL preservation and no silent trust/key replacement during rollback.
- Implement crash/power-loss/partial-swap/failed-migration/restore recovery, durable outbox
  and one active installation owner/epoch. D4 mode rollback is not that lifecycle engine.
- Define safe server transfer and client refresh: deliberately move the canonical DNS/owned
  mappings, preserve or explicitly rotate the issuer, remove obsolete owned firewall/trust
  additions, retain audit/deny state and prevent duplicate collectors/occurrences.
- Extend uninstall/restore/update handling for D4's private web state/service identities and
  owned exposure; older D2 lifecycle scripts are not certified for the new layout.
- Rebuild one same-source three-platform set with sufficient disk, then execute the native
  acceptance matrix above and the D3/D5 failure cases before any production approval.

Private evidence pointer: `/tmp/philcst-deployment-d4-workspace.txt`. It identifies isolated
Laravel/JUnit, browser/JS/build, network/Caddy/CA, PowerShell, package/update, native Go and
preservation logs. Key final logs: `laravel-full-passed.txt`, `laravel-final.xml`,
`network-tests-delivery.log`, `caddy-offline-delivery/results.json`, `*-tests-delivery.log`,
`go2rtc-build-final.log` and `client-artifacts-final.json`. These are evidence, not backups,
publisher trust material or a production installation.

**D4 source/fixture implementation complete. Stop and wait for “Go Deployment D5.”**
