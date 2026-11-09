# Connect a Windows or Mac browser client

A client needs only a supported browser and these small helpers. It installs no database,
PHP, detector, collector or background service. Multiple clients connect to the same main
computer; never install a second server to view a gate.

Get the main computer's reserved private IPv4 address, this installation's **public root
certificate**, and its 64-digit SHA-256 fingerprint through an independent administrator
handoff (for example verified USB and a separately read fingerprint). Do not trust a root
retrieved from an unauthenticated network page. No APP_KEY, API key, password, device login
or private CA key belongs on a client. Check date/time on both computers.

## Windows: Edge/Chrome

Open PowerShell **as Administrator**. Elevation is for a managed hosts entry, local-machine
public-root trust and a shared Open shortcut; no service is installed.

```powershell
.\Connect-to-PHILCST.ps1 -Action Connect -ServerAddress 192.168.44.10 `
  -RootCertificate 'E:\PHILCST-public-root.crt' -RootFingerprint '64_HEX_SHA256'
```

If school DNS already maps the canonical name exclusively to this address, add
`-NameResolution DNS`; otherwise the default owns one marked hosts block. Pre-existing
unowned canonical mappings (including IPv6) stop enrollment for administrator review.
Wrong root/fingerprint/address/hostname fails before mapping or trust changes.

Open **Open PHILCST Server.url** on the shared desktop or type
**https://philcst.vms.internal**. Restart browsers after first trust enrollment.

```powershell
.\Connect-to-PHILCST.ps1 -Action Status
.\Connect-to-PHILCST.ps1 -Action Repair
.\Connect-to-PHILCST.ps1 -Action Remove
```

Repair uses the owned verified root/receipt; provide explicit address/root arguments if
needed. A different issuer requires explicit removal/re-enrollment. Removal deletes only
helper-added trust and mapping/shortcut ownership; pre-existing roots are retained. It does
not stop the server or change any records. Preserve the administrator-owned receipt under
`C:\ProgramData\PHILCST-VMS-Client` until removal. Native Windows execution remains subject
to the D4 acceptance matrix; PowerShell 7 parsing on Mac is not PowerShell 5.1 certification.

## Mac: Safari/Chrome, Intel or Apple Silicon

In Terminal, from this helper directory:

```sh
sudo ./Connect-to-PHILCST.command --action connect --server 192.168.44.10 \
  --root /Volumes/USB/PHILCST-public-root.crt --fingerprint 64_HEX_SHA256
```

Add `--dns` only when school DNS already resolves exclusively to the selected IPv4. This
uses built-in shell/security/curl tools, without Homebrew/Python/Node/developer tools.
It checks the selected public root and HTTPS name/address before enrolling trust. System
Keychain import is constrained to SSL and the canonical hostname. Conflicting unowned trust
requires explicit administrator review.

Open `/Users/Shared/Open PHILCST Server.webloc` or the canonical URL. Restart Safari/Chrome
if already open before trust enrollment.

```sh
./Connect-to-PHILCST.command --action status
sudo ./Connect-to-PHILCST.command --action repair
sudo ./Connect-to-PHILCST.command --action remove
```

Ownership receipts/public root live under `/Library/Application Support/PHILCST-VMS-Client`.
The helper never launches a collector. Removal affects only owned additions; existing roots
and unrelated mappings stay intact. A certificate/clock or wrong-server error must be fixed
at its source. Do not use a browser certificate-warning bypass.

## Firefox

Current Firefox can use third-party roots from the Windows/macOS store when its
**Allow Firefox to automatically trust third-party root certificates you install** setting
is enabled (`security.enterprise_roots.enabled`). Organizational policy may change that.
Verify the setting with the administrator, restart, and check the certificate fingerprint.
If a separately managed Firefox profile needs an explicit CA import, import only this public
root under Settings → Privacy & Security → Certificates → Authorities, after independently
checking the same SHA-256. Record that manual ownership and remove it separately when leaving
this server; the helper does not modify Firefox profile policy or silently import into NSS.

Official references: [Mozilla enterprise roots](https://support.mozilla.org/en-US/kb/setting-certificate-authorities-firefox),
[Apple certificate trust](https://support.apple.com/guide/keychain-access/change-the-trust-settings-of-a-certificate-kyca11871/mac).
Native Edge/Chrome/Safari/Firefox trust and disconnected multi-PC acceptance must be recorded
on actual targets. Source/fixture checks alone do not certify them.
