# Main server: offline named HTTPS

Use only a newly built, verified D4 package on an isolated acceptance target until native
acceptance is complete. Existing D2/D3 packages do not contain this implementation. Do not
copy these files over a running older installation; D5 owns upgrades/recovery. The working
installation was not changed by development tests.

The one browser address is **https://philcst.vms.internal** on TCP 443. Never rename the
computer, use `.local`, open numeric browser URLs or disable certificate checks. One main
server owns the local database and all collectors. A client never owns a copy of them.

## Fresh Windows x64 main computer

Open PowerShell **as Administrator**. Verify the complete package's externally supplied
checksum/publisher identity before invoking its installer. Windows ARM/emulation is not a
server target. Default root is `C:\PHILCST-VMS`.

```powershell
# Only on an isolated development acceptance machine, for unsigned candidates:
& 'STAGING\PHILCST-VMS\app\deploy\windows\install-package.ps1' `
  -Payload 'STAGING\PHILCST-VMS' -Root 'C:\PHILCST-VMS' -AllowDevelopmentCandidate
```

Fresh installation defaults to **This computer only**. The Inno installer uses the same
local-only path. No LAN allow rule is created. The browser host is mapped to 127.0.0.1.
Collectors use the separate loopback HTTP 8080 integration endpoint with X-Api-Key, not
unverified HTTPS. PHP FastCGI, go2rtc administration and detector control stay loopback.

After services start, inspect the public-root fingerprint, then explicitly enroll trust:

```powershell
$root='C:\PHILCST-VMS'
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" status --root $root
# Compare the root fingerprint with the installation-local administrator handoff.
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" trust-root --root $root --fingerprint '64_HEX_SHA256'
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" export-root --root $root --output 'E:\PHILCST-public-root.crt'
```

Only that public certificate and its independently conveyed fingerprint go to clients. Do
not copy `.env`, API keys, camera credentials, DB, TLS directory or any private key.

## Fresh Intel/Apple Silicon Mac main computer

Use the native architecture package. Extract outside `/Library/Application Support/PHILCST-VMS`.
Verify its external checksum/publisher evidence. On an isolated unsigned-candidate target:

```sh
./Install.command --allow-development
```

Then, in Terminal:

```sh
ROOT='/Library/Application Support/PHILCST-VMS'
PY="$ROOT/current/runtime/python/bin/python3"
CTL="$ROOT/current/app/deploy/network/server.py"
sudo "$PY" -B "$CTL" status --root "$ROOT"
sudo "$PY" -B "$CTL" trust-root --root "$ROOT" --fingerprint 64_HEX_SHA256
sudo "$PY" -B "$CTL" export-root --root "$ROOT" --output /Volumes/USB/PHILCST-public-root.crt
```

Trust enrollment is an explicit administrator action. Caddy's automatic trust import is
turned off. Caddy alone needs root for port 443 on the inspected Mac; PHP, detector, live
relay and scheduler retain the non-login service identity. Devices retains its existing
root adapter-address workaround. No broad sudoers permission is installed. The private
issuer is root-only under `shared/web/tls`; the app cannot read it.

After enrollment, use Controls/Open.command or the canonical URL. Complete protected
first-admin setup with the separately delivered expiring setup code; no demo password is
provided. Reinstall preserves issuer/accounts/keys and does not reopen setup.

## Explicit LAN sharing and return to local isolation

Have the network administrator reserve a stable private **IPv4** on a deliberately chosen
LAN interface (DHCP reservation or separately configured static IP). The helper never
changes addresses/router/DNS/network profiles. IPv6 LAN sharing is deliberately refused;
remove conflicting AAAA/hosts aliases through an administrator before enrollment. Other
unrelated IPv4/IPv6 mappings are preserved.

Create an administrator-owned JSON configuration outside the immutable release, for example:

```json
{"schema":1,"mode":"lan","address":"192.168.44.10","interface":"Ethernet","remote_scope":"192.168.44.0/24"}
```

On Mac the interface may be `en0`; use the actual selected interface/address. Windows
requires the selected interface to already have a Private or Domain profile. The scope must
lie within that interface's subnet. Do not reuse the example address without checking it.

Windows (elevated):

```powershell
& "$root\runtime\python\python.exe" -B "$root\app\deploy\network\server.py" configure --root $root --config 'C:\Admin\philcst-lan.json'
```

Mac:

```sh
sudo "$PY" -B "$CTL" configure --root "$ROOT" --config /path/to/philcst-lan.json
```

The server still maps its own canonical name to loopback. Clients use school/router DNS
when configured correctly, otherwise the client helper owns one identifiable hosts block.
Existing contradictory/unowned canonical mappings stop setup; helpers do not replace them.
DNS must resolve exclusively to the chosen IPv4 address; conflicting A/AAAA records fail.

To return to **This computer only**, use the same command with:

```json
{"schema":1,"mode":"local","address":null,"interface":null,"remote_scope":null}
```

The generated browser listener binds only loopback, go2rtc advertises/binds loopback, and
owned LAN firewall entries are removed. Devices/accounts/history remain intact. Configuration
is validated first, and ordinary application/restart failure restores prior config/mappings/
owned exposure. If rollback itself fails, leave public services stopped for administrator
review; never reset the DB/keys. Configuration changes briefly interrupt web/integration and
video. Initialized D5A packages use owned producer drain, a writer fence and retained outbox
work for these changes; read MAINTENANCE.md. Use a quiet period: intentional capture pause
can miss physical traffic. This is not a D5B/D5C crash-safe data/update transaction.

## Ports and firewall limits

- TCP 443: browser HTTPS, loopback only or loopback + selected IPv4. Caddy also checks the
  selected remote subnet. No port 80 redirect or HTTP/3 UDP 443 listener is enabled.
- TCP/UDP 8555: negotiated encrypted WebRTC, bound/advertised on the selected IPv4 in LAN
  mode and loopback in local mode. No public STUN/TURN defaults remain.
- TCP 8080: loopback integration/auth-subrequest/health only. All integration requests still
  need the installation API key. Browser login/pages are unavailable on this listener.
- TCP 9001 (Mac) / 9001–9004 (Windows): loopback FastCGI only.
- TCP 1984: go2rtc API loopback only; TCP 8765: detector loopback only. RTSP relay disabled.
- Camera/reader discovery/transport remains the existing server-to-device behavior.

Windows rules are uniquely owned by installation root and limited to the selected program,
interface, local IP, remote subnet and Private/Domain profiles. Rules do not expose internal
ports. Local mode removes those owned rules only. Existing broad administrator-created
rules are not silently rewritten and must be audited during native acceptance.

On Mac, only missing application-firewall entries for Caddy/go2rtc are added (when the
firewall is enabled), and only helper-owned additions are removed. The firewall is never
disabled; Block All and conflicting existing blocks require administrator resolution.
macOS application firewall is not a per-subnet filter. The Caddy subnet check protects HTTPS;
WebRTC uses the selected bind, authenticated signaling and encrypted negotiated sessions,
with no claim of packet-level subnet isolation. No global PF rules are installed/overwritten.

## Issuer persistence, diagnostics and renewal

Windows issuer: `ROOT\state\web\tls`; Mac issuer: `ROOT/shared/web/tls`. These are durable
state outside app/releases, protected from PHP's service identity. Caddy uses its internal
issuer and renews short-lived leaves locally; there is no public ACME/external DNS dependency.
Status prints public root fingerprint/validity and local UTC clock only. Check the machine
clock, root/intermediate validity, private-state permissions and HTTPS readiness if clients
report errors. Never fix an error by disabling TLS validation or silently generating a new CA.
Missing root/intermediate state or a changed fingerprint refuses further configuration.

The original root is explicitly pinned in subsequent Caddy configuration. Root renewal/
rotation is an administrator maintenance operation requiring separately verified replacement,
controlled client re-enrollment and removal of old owned trust after migration. There is no
automatic root replacement in D4. TLS issuer trust is separate from D3 software-update keys.
D5 backup/restore/transfer must preserve the complete CA keys/certificates, identity receipt,
network config and ownership state without placing private material in browser exports.

## Media and revocation

Historical `/storage/...` compatibility URLs reach authenticated Laravel exact-reference
checks, even when an old public/storage symlink exists. Generic local-disk serving is disabled.
New occurrence images keep private UUID/reference checks. Traversal, outside symlinks,
unreferenced files and non-image extensions fail. No history is rewritten by plate/name.
Print snapshots use the same authenticated origin. Server-side PDF generation is not added;
Save as PDF remains the browser print workflow.

New HLS/image/overlay requests recheck account/session status. The packaged go2rtc derivative
closes each viewer at 30 seconds and the detector closes packaged MJPEG after 30 seconds,
forcing authenticated reconnect. The normal player renews WebRTC at 25 seconds and checks
session status every 10 seconds. Timer closure is server enforced, not merely JavaScript.
Already delivered frames/browser buffers cannot be recalled. Live-camera codec/fallback
behavior and loaded multi-client revocation timing still require native device acceptance.

See CLIENT-CONNECTION.md in Connect-to-Server and DEPLOYMENT-D4.md for executed fixtures,
NOT RUN acceptance cases and source/package identities. D5 update/backup/transfer work has
not been implemented by this phase.
