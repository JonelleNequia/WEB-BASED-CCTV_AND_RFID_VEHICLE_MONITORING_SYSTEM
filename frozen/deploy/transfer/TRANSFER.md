# D5D — authenticated server handoff and disaster inspection

This is a controlled source/fixture implementation. Actual installed Windows,
Intel/arm64 Mac, two-machine offline and power-loss acceptance remains required.
No production source/target pair is authorized by the empty packaged
`deploy/transfer/compatibility.json`. A successful fixture does not approve broad
versions/platforms. This workflow never synchronizes two active databases.

## Prerequisites and trust

Use one reviewed same-version/source/schema complete target package, trusted
native runtimes, initialized D5A and D5B state, and an independent retained D5D
controller capsule. Old D2/D3 packages and D5C-only capsules cannot enforce D5D
retirement. Do not copy this checkout over a working installation. Perform any
needed application/schema upgrade separately through a demonstrated D5C path.

The target must be a known fresh installed root, with non-account defaults only,
no users/vehicles/history/deliveries. Conflicting operational installations are
refused. Local fixed ACL-capable disks are required, never shared SQLite.
Use the target's native runtime/service account configuration. No executables,
registry/launchd/OS users/SIDs/firewall/hosts/trust-store definitions come from
source media. Source gate IDs, assignments, calibration and encrypted credentials
are data; their hardware readiness needs independent acceptance.

Each host supplies its own administrator-protected RSA transfer key (2048+ bits),
with its public SPKI SHA-256 independently exchanged, along with host/root
identity. The helper never accepts a key supplied by an incoming archive as its
authority. This key must differ from TLS issuer and software publisher keys.
Generate/hold real authority using the institution's approved key custody process;
no production or fixture key is supplied by this repository. The protected
bootstrap checks the private/public match and externally verified source hash.

Software update trust, transfer authority and TLS issuer remain separate. All
transfer receipts expire after seven days and require an accurate independently
checked local clock. Expired handoffs remain fenced; do not bypass expiry or
regenerate missing keys. The disaster path below does not repair missing trust.

## Installed controller commands

Run on the corresponding **main server**, elevated Windows PowerShell 5.1 or Mac
sudo, using the retained installation-trusted interpreter/controller. Initial D5D
capsule registration uses the D5C bootstrap procedure from an authenticated D5D
package; do not overwrite a sole older recovery controller.

Windows, use the protected receipt to locate the retained controller:

```powershell
$root='C:\PHILCST-VMS'
$id=(Get-Content "$root\state\update-controller.json" -Raw | ConvertFrom-Json).id
$capsule=Join-Path "$root\state\update-controller" ([guid]::Parse($id).ToString())
$py=Join-Path $capsule 'runtime\python\python.exe'
$ctl=Join-Path $capsule 'app\deploy\transfer\cli.py'
& $py -I -B $ctl status --root $root
```

Mac, native architecture:

```sh
ROOT='/Library/Application Support/PHILCST-VMS'
ID=$(/usr/bin/plutil -extract id raw -o - "$ROOT/shared/update-controller.json")
CAPSULE="$ROOT/shared/update-controller/$ID"
PY="$CAPSULE/runtime/python/bin/python3"
CTL="$CAPSULE/app/deploy/transfer/cli.py"
sudo "$PY" -I -B "$CTL" status --root "$ROOT"
```

Below, substitute `& $py -I -B $ctl` on Windows or `sudo "$PY" -I -B "$CTL"`
on Mac, and the actual root. Public peer JSON has exactly `key_id`, `public_key`,
`host`, `root`, `environment`. It must arrive through independent administrator
verification, not as authority inside the transfer package.

```text
bootstrap --root ROOT --private-key PROTECTED_EXTERNAL_TRANSFER_KEY --fingerprint INDEPENDENT_SPKI_SHA256 --confirm-source AUTHENTICATED_SOURCE_SHA256 --output PUBLIC_PEER_JSON
# Exchange public peer JSON/fingerprints and host/root identity independently.
enroll --root ROOT --peer INDEPENDENTLY_VERIFIED_OTHER_HOST_JSON --fingerprint OTHER_HOST_SPKI_SHA256
```

Bootstrap registers one protected authority and initial local active-owner receipt.
It does not install services, touch devices or enroll OS trust. Repeated/conflicting
bootstrap is refused. Installing the complete target remains its separate native
installer workflow; the transfer controller never runs source-side code from media.

## Planned transfer, exact confirmations and custody

1. Verify the target native complete package, free disk, current reviewed
   application/schema/runtime transfer pair and empty operational state.
2. On target, create its one-time source-key-bound challenge, closing target writers:

```text
challenge --root TARGET_ROOT --source-key VERIFIED_SOURCE_KEY_ID --actor "Local administrator" --output CHALLENGE_JSON
```

3. On source, inspect/approve target nonce, key, machine/root/package/SQL identity,
   recovery downtime and target network decision. TLS preservation is the only
   implemented transfer choice. Rotation is a separately authenticated root and
   client re-enrollment procedure; it is deliberately refused in this handoff.

Decision JSON example for a target already configured local-only:

```json
{"tls":"preserve","target_network":{"schema":1,"mode":"local","address":null,"interface":null,"remote_scope":null},"isolation_required":true,"custody":"approved-encrypted-volume"}
```

For LAN, first explicitly configure the target's real NIC/reserved private address
and owned D4 exposure in the separate D4 workflow, while the old host is isolated
if addresses conflict. Use that exact target network configuration in decisions.
The transfer does not assign an IP, rename a computer, modify DNS/router or import
source firewall state. Revalidation rejects a changed target configuration.

```text
plan --root SOURCE_ROOT --challenge CHALLENGE_JSON --decisions DECISIONS_JSON --actor "Local administrator"
prepare --root SOURCE_ROOT --challenge CHALLENGE_JSON --decisions DECISIONS_JSON --actor "Local administrator" --confirmation EXACT_PLAN_SHA256
export --root SOURCE_ROOT --output NEW_PROTECTED_USB_DIRECTORY --custody approved-encrypted-volume
```

Prepare closes source admission, stops/drains/fences owned writers and takes a
coherent verified D5B recovery set. Persisted source retirement precedes signed
transfer export. Existing source material is retained. The export is a directory
containing `backup.zip`, `receipt.json`, `TRANSFER.json` (signed target-bound v1
manifest). It contains APP_KEY/API credentials, issuer private material, accounts,
media and outbox evidence. **The tool does not encrypt it.** Require an approved
encrypted volume (for example institutionally managed BitLocker/APFS custody),
restricted ACLs and separately held volume-recovery material. The custody argument
is an administrator attestation, not an encryption detector or encrypted archive.
FAT/unprotected public folders are unsupported. No sensitive backup is auto-deleted.

4. Carry the complete protected set to the intended target. Inspect and confirm
   the canonical signed envelope SHA-256, not an unsigned checksum alone:

```text
inspect --root TARGET_ROOT --archive PROTECTED_TRANSFER_DIRECTORY
import --root TARGET_ROOT --archive PROTECTED_TRANSFER_DIRECTORY --confirmation EXACT_SIGNED_ENVELOPE_SHA256
promote --root TARGET_ROOT
verify --root TARGET_ROOT
```

Signatures, trusted sender, target nonce/host/root, source retirement, exact
source/target path, recovery point, archive/receipt/content hashes, safe paths,
SQLite integrity/relationships, decryption, media, generation/receipts/outbox,
update trust and monotonic state are validated before use. Extraction executes
no incoming helper. Protected copies are checked again at use. Target migrations
are not run by transfer; unknown schema/version changes are refused.

Promotion is journaled with old/new hashes and retained original target components.
It preserves source installation/generation and original envelope/time/camera/
media/ACK identity. New **ownership** epoch/token is separate. Retained deliveries
must still pass their exact unchanged generation/checksum/receipt rules; no global
generation bypass is added. Integration additionally requires the current owner's
transport header, supplied by managed collectors; original payloads remain intact.
Legacy senders without the new header are rejected once D5D ownership is enabled.
This token prevents accidental old sender use; it is not a distributed lease.

Environment/DB/cache/device paths, target-owned configuration and native permissions
are regenerated from target code. Source TLS issuer/root is preserved under target
protections, while source OS trust/exposure receipts are not treated as target OS
trust. Accounts remain; sessions/session versions, temporary activation/reset/setup
codes and stale prepared update/browser requests are invalidated. Completed setup
stays closed. Publisher generation/revocations and sequence/clock floors survive;
platform-specific update key scope is not widened by transfer.

5. **Physically/network isolate the source.** Confirm power/cabling or an enforced
   administrative network quarantine, including device connectivity. Silence or
   timeout is not evidence. Inspect address conflicts separately. There is no
   automatic failover. Then on target:

```text
isolation --root TARGET_ROOT --transfer TRANSFER_UUID --method powered-off-and-disconnected --evidence "Named technician verified power and network isolation" --actor "Local administrator" --address-conflict-checked
activate --root TARGET_ROOT --confirmation EXACT_SIGNED_ENVELOPE_SHA256 --output ACTIVATION_RECEIPT_JSON
```

Target application/pinned HTTPS readiness must pass before collectors reopen.
Camera/reader availability is reported separately; no fake detections are generated.
The signed isolation statement records administrator evidence; software cannot
prove that someone unplugged a different computer. Native two-machine acceptance
must verify the actual isolation procedure and address-conflict detection.

6. Carry the signed activation receipt back to the retired source for recordkeeping:

```text
acknowledge --root SOURCE_ROOT --receipt ACTIVATION_RECEIPT_JSON
```

The source was already retired before export and remains retired if this receipt
is delayed/lost. Both source/target startup, Python/PHP writer admission, scheduler,
maintenance Resume and ordinary D5B/D5C recovery honor protected ownership. Retired
source snapshots restored outside this managed workflow or a privileged admin
rewriting receipts cannot be remotely fenced by these local controls. Physical/
network isolation is therefore mandatory, not a claim of automatic split-brain
prevention. No protection against arbitrary administrator machine-snapshot restore
or malicious copied keys is claimed.

## Interrupted operations and cancellation

Inspect `status` and preserve `state|shared/transfer`, D5B safety material, all
protected keys and lifecycle receipts. No failure automatically restarts writers.
For a dead controller, review exact PID ownership before removing locks:

```text
recover-owner --root ROOT
# Separately use retained D5A maintenance.py recover --root ROOT --operation UUID
# only when its own persisted operation lock belongs to that proven-dead process.
recover-export --root SOURCE_ROOT
recover-import --root TARGET_ROOT
promote --root TARGET_ROOT
verify --root TARGET_ROOT
activate --root TARGET_ROOT --confirmation EXACT_SIGNED_ENVELOPE_SHA256
receipt --root TARGET_ROOT --output SIGNED_RECEIPT_JSON
```

`recover-export` completes the already captured exact retirement/export intent;
it does not reopen source writers. `recover-import` only restages from protected
reverified signed bytes before promotion. `promote` resumes recorded hash moves;
unexpected/missing active/staged bytes stay fenced. After activation intent is
persisted, cancellation is forbidden even if startup failed, because traffic may
have been accepted. Repair target readiness/recover its activation; do not blindly
resume the source or discard later target activity. Receipt recovery after first
target startup does not overwrite newer data.

Before any target activation intent, target can permanently consume/cancel its
nonce and issue a signed rejection:

```text
reject --root TARGET_ROOT --output TARGET_REJECTION_JSON
cancel-source --root SOURCE_ROOT --receipt TARGET_REJECTION_JSON
```

The target remains permanently cancelled/fenced; this nonce/package cannot later
activate. Only that verified target rejection permits controlled source resume.
If the target is unavailable or its cancellation cannot be independently verified,
keep the source retired/isolate the target and use a separately authorized recovery
procedure. Returning after new target traffic requires another coherent handoff to
a fresh target; it is not a database-only rollback or merging later databases.

## Clients and canonical HTTPS

Keep `https://philcst.vms.internal`. Independently deliver the target address and
preserved public-root fingerprint. Existing helpers validate the new endpoint
against that public root before changing only owned mappings. Windows:

```powershell
.\Reconnect-to-PHILCST.ps1 -OldServerAddress 192.168.44.10 -ServerAddress 192.168.44.20 -RootCertificate E:\PHILCST-public-root.crt -RootFingerprint INDEPENDENT_SHA256 -OldServerIsolated
```

Mac:

```sh
sudo ./Reconnect-to-PHILCST.command 192.168.44.10 192.168.44.20 /Volumes/USB/PHILCST-public-root.crt INDEPENDENT_SHA256 --old-server-isolated
```

Use `-NameResolution DNS` / `--dns` only after the school administrator deliberately
changes DNS. Conflicting A/AAAA or unowned aliases fail; no router/DNS change is
automatic. Helpers reject stale old-address receipts and a different issuer. For
approved issuer rotation, separately verify the new root, explicitly remove only
old owned enrollment using D4 Remove, then re-enroll. Unrelated/borrowed trust and
hosts entries remain. Sign in again after transfer; copy unsaved work before
explicit refresh. Browser clients install no runtimes, DB or collectors.

## Explicit disaster inspection — activation blocked

```text
disaster-inspect --root TARGET_ROOT --archive PROTECTED_BACKUP_OR_COLLECTION_DIRECTORY
```

This inspects the newest declared coherent recovery point among up to 100 supplied
sets, reports pending deliveries, missing later activity and unknown newer security
state, and **cannot activate**. Self-contained D5B checksums do not authenticate a
dead source. No institutional independently held disaster-authority/key recovery
policy, authenticated recovery-point signature and newer security/revocation escrow
have been provided. These are concrete missing prerequisites. Neither an old
backup nor an unreachable server establishes that later lost UIDs, account changes,
trust revocations or movements never occurred. Lost APP_KEY or issuer private keys
are not regenerated. Even with recoverable keys, older-state loss needs explicit
institutional approval and verified old-host isolation. This path remains blocked
rather than weakening normal host binding or disguising recovery as a new install.
