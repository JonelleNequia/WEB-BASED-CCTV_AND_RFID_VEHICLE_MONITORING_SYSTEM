# PHILCST Vehicle Monitoring

Offline-first Laravel/Python vehicle monitoring with one main server and optional
Windows/Mac browser clients. Registered vehicles can use exact eligible plate
identification without RFID. Evidence, Review, occurrence history and campus state
remain separate from registration and device inventory.

## Installation and delivery status

Start with [OPERATOR-GUIDE.md](OPERATOR-GUIDE.md) and the actual artifacts/acceptance
in [DEPLOYMENT-D6.md](DEPLOYMENT-D6.md). Current work prepares complete versioned
**development acceptance candidates**, not a certified production deployment.
Production signing/native acceptance and exact compatibility gates remain explicit.
There is no published download link asserted by this checkout.

- Windows main server: native x64 Intel/AMD, Windows 10+ target.
- Intel Mac: native x86_64; historical PHP/Torch maintenance blocks production.
- Apple Silicon Mac: native arm64; final library audit defines minimum OS.
- Browser clients: small Connect/Reconnect bundles, no server runtimes/database.

Complete targets do not require XAMPP, Homebrew, PHP/Python, Composer, Node or Git.
Ordinary installed operation remains offline. Use **https://philcst.vms.internal**;
local-only is default and LAN sharing/trust enrollment is explicit. There is no
shipped admin password: [personal setup and account activation](ACCOUNT-SECURITY.md)
use privately delivered expiring codes.

## Application behavior

Registry status and campus state are independent. RFID is optional; tag display
numbers preserve leading zeros and differ from reader UID. Lost UIDs remain
unavailable. Exact plate normalization preserves character order, zeros and O/0,
I/1 distinctions. Historical Guest occurrences remain historical after later
registration. Registered occurrences remain in Activity Logs and are excluded from
Guests. Uncertain identifier/OCR evidence remains in Review with its original media.

Settings uses Gates/Advanced. At least two non-archived gates remain; inactive
gates count. Archiving preserves stable IDs, history and devices while releasing
operational assignments. Activity Logs and Registry/Guests reporting share server
filters; occurrence snapshots and plate crops retain exact event ownership.

Current implementation reports:
[Registry 1](REGISTRY-PHASE1.md), [2](REGISTRY-PHASE2.md),
[3](REGISTRY-PHASE3.md), [4](REGISTRY-PHASE4.md), [5](REGISTRY-PHASE5.md),
[6](REGISTRY-PHASE6.md), [7A](REGISTRY-PHASE7A.md), [7B](REGISTRY-PHASE7B.md),
[7C](REGISTRY-PHASE7C.md), [7D](REGISTRY-PHASE7D.md), [7E](REGISTRY-PHASE7E.md),
[literal plate reconciliation](REGRESSION-LITERAL-PLATE-RECONCILIATION.md),
[OCR readability](OCR-READABILITY-FIX.md),
[automatic classification](OCR-AUTOMATIC-CLASSIFICATION.md),
[optional RFID/anomaly investigation](OPTIONAL-RFID-ANOMALY-FIX.md).
These reports record their own historical verification; they are not installation
instructions or permission to migrate working data.

## Lifecycle and development

Use the [operator guide](OPERATOR-GUIDE.md) for current install/Start/Stop, backup
custody, update/transfer blocks, client enrollment and troubleshooting. Earlier
D1–D5 reports are evidence/handoffs, not competing live-install recipes.

[DEVELOPMENT.md](DEVELOPMENT.md) is the isolated development/test path.
[Browser fixtures](tests/browser/README.md) use private synthetic DBs/devices.
Never run test seeds, reset commands, development autostart or two collectors
against an installed server. Builders and target installers are separate.
