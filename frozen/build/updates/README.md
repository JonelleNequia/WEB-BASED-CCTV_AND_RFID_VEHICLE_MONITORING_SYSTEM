# D3 automatic-update preparation

This directory supplies **read-only contracts and build tools**, not an installed updater.
It contains no network client, scheduled job, Update button, installer, active-data discovery,
service control or trust-store writer. D5 must install an authenticated, protected copy of
the verifier and public trust material before using these contracts on a target.
Never execute the verifier, PHP, Python, state or trust store from an incoming candidate.

## Metadata and identity

`update-manifest.schema.json` describes the `philcst.update.v1` envelope. `contract.py`
is the authoritative semantic validator: JSON Schema alone cannot verify signatures,
SemVer ordering, expiration, installed compatibility or archive contents. The complete
`example.update.json` is synthetic, unsigned and deliberately unusable for installation.

Each platform has its own manifest: `windows-x64` (Windows x64 ZIP), `macos-x86_64`
and `macos-arm64` (tar.gz). Windows Setup EXEs remain fresh-install delivery artifacts;
they are not the complete update-import format. OS versions use numeric major/minor/patch
comparison. Windows currently records the existing Windows 10 floor (`10.0.0`), not a
newly certified Windows build requirement; D5/D6 must refine the supported Windows matrix.

The signed object includes product/schema, SemVer/channel, source commit/canonical source
hash/common build identity, platform/minimum OS, explicit upgrade-origin ranges, accepted
and target schema/runtime IDs, immutable archive filename/size/SHA-256, the inner content
manifest hash, and issued/expiry times. The envelope adds signatures with an algorithm,
public-key fingerprint and base64 signature. It never supplies a verification key.

`CONTENT-MANIFEST.json` lists files *inside* a package. The update manifest is a sidecar
*outside* that package and binds the whole archive plus its content manifest. A content
hash is integrity evidence, not publisher authentication. Optional existing D2 content
signatures and platform code signing remain separate from the update-envelope signature.

Schema IDs hash the canonical captured migration path/hash map. Runtime IDs hash platform,
runtime versions and dependency-lock hashes from frozen BUILD-INFO. These identify source
contracts, not a running database's actual migration ledger or integrity. D5 must inspect
copies of the real database and verify migrations/decryption before accepting installed
state. `compatibility.json` intentionally authorizes **no upgrade origins or accepted
schema/runtime IDs**. Populating those requires reviewed D5 compatibility and recovery work.

Versions follow [SemVer 2](https://semver.org/). Stable is the default and requires a final
version. Prerelease and development manifests require their exact channel to be selected;
neither silently enters stable selection. Numeric prerelease identifiers compare numerically;
build metadata does not affect precedence. Equal precedence is already installed only when
the exact version string, source/build identity and target contracts agree. Other same-version
builds and ordinary downgrades fail. Newer versions also need an approved origin range and
accepted installed schema/runtime IDs. There is no emergency downgrade override in D3.

## Installation trust and signatures

Algorithm v1 is RSA-PKCS1-SHA256 with RSA keys of at least 2048 bits. Sign exactly
`contract.canonical(signed)` (sorted compact ASCII JSON, escaped Unicode, no NaN), not the
pretty-printed document. The key ID is SHA-256 of DER SubjectPublicKeyInfo from a PUBLIC
KEY PEM. The helper uses the installation-trusted PHP OpenSSL extension and requires
`openssl_verify(...) === 1`; errors never count as success.

The local store has this shape (placeholders below are not valid verification material):

```text
schema: philcst.update-trust.v1
product: philcst-vms
environment: production
generation: positive integer
keys:
  <SHA-256 SPKI fingerprint>:
    public_key: <PUBLIC KEY PEM, never a private key>
    channels: [stable, prerelease, development]  # narrow to the authorized scope
    platforms: [windows-x64, macos-x86_64, macos-arm64]
    roles: [update, rotate]
    not_before: <UTC timestamp>
    not_after: <UTC timestamp>
    revoked: false
```

D5 must provision initial public roots through an authenticated installer or separately
verified administrator procedure, protect them with installation ownership/ACLs, and retain
their generation/revocation state outside replaceable release directories. No production
root/private signing credential is supplied here. A downloaded key, self-signed sidecar,
checksum from the same untrusted source or ad-hoc macOS signature cannot bootstrap trust.
Private signing keys must remain outside the repository and delivered artifacts. The
optional builder signing hook refuses repository-local private-key paths. CI emits unsigned
candidates until publisher credentials and protected signing/approval are arranged.

`validate_rotation` accepts a `philcst.update-key-rotation.v1` signed proposal containing
product, `previous_trust_sha256` (canonical current store), next `generation`, complete
`replacement_trust`, issued/expiry times. An existing, unrevoked, current key with `rotate`
authority must sign it. Replacement channel/platform scopes cannot exceed that key's scope;
an active update verifier must remain. Self-authorized replacements and generation replay
fail. The returned proposal never writes trust. D5 must add admin confirmation, atomic
protected persistence, a monotonic rollback guard, revocation propagation and an independently
authenticated recovery procedure if the old signing authority is lost/compromised.

Fixture trust requires both explicit `--fixture-trust` and fixture-marked state/store. Tests
generate ephemeral private keys in private temporary folders and remove them at teardown.
No fixture private key or production-trusted fixture root is checked in or packaged.

## One local/downloaded validation interface

After a future downloader has staged a complete archive, it uses exactly the same verifier
as a USB/local import. `--delivery` describes provenance only; it grants no exemption.

```sh
TRUSTED_PYTHON -B /trusted/verifier/validate.py \
  --manifest /staging/candidate.update.json --package /staging/COMPLETE-PACKAGE.tar.gz \
  --state /protected/installation-update-state.json \
  --trust-store /protected/update-trust.json --php /trusted/runtime/php \
  --channel stable --delivery local
```

Use `--delivery downloaded` for the future download path. On Windows, `validate.ps1`
exposes the same arguments (`-Python`, `-Php`, `-PhpIni`, `-Manifest`, `-Package`, `-State`,
`-TrustStore`, `-Channel`, `-Delivery`). Dynamic Windows OpenSSL needs a trusted PHP ini;
no target openssl command-line executable is required. Invocation arguments are arrays,
including paths with spaces. Failure exits nonzero with JSON reason; success reports
`eligible-for-D5-review` or `already-installed`. Neither outcome installs anything.

Trusted state (`philcst.installation-update-state.v1`) contains product, environment,
platform ID, actual OS version, installed version/channel, source commit/hash/build identity,
`schema_id`, `runtime_id`, and an administrator-controlled `max_expanded_bytes`. D3 does
not generate this state by opening a live installation. Never take it from the candidate.

Validation rejects unsigned/untrusted/revoked/out-of-scope signatures, expired metadata,
unknown or missing fields, duplicate JSON keys, malformed/truncated files, wrong filename,
size/hash, architecture, OS floor, origins, schema/runtime or build identity. It streams
archives without extraction or executing candidate code; requires PHILCST-VMS as root;
rejects traversal, absolute paths, symlinks/hardlinks, devices, encrypted ZIPs, Windows
reserved names, case/Unicode-normalization collisions, file/directory shadowing and
expansion beyond local limits. Every regular file
must match the bound content manifest; unexpected files fail. The existing optional
CONTENT-SIGNATURE sidecar is also bound by the full archive hash.

`select_candidate` is a pure function over supplied envelopes, not a network implementation.
Empty/unavailable discovery means no eligible update. App startup/monitoring never calls it,
so discovery absence cannot interrupt offline operation. A future D5 adapter must handle
timeouts/errors, trusted time, replay/freeze protections and download limits separately.

## Manual builds and verification

Windows and Mac builders generate `ARCHIVE.update.json` after their complete archive checks.
The manual-only workflow uploads sidecars with platform artifacts and separately aggregates
metadata into `philcst.update-matrix.v1`. Aggregation requires all three platform IDs and
identical product/version/channel/source commit/hash/build identity. This review index is
not a trusted discovery root. No publication or installed execution occurs.

```sh
python3 -B build/updates/generate.py --stage EXTRACTED-STAGE --package COMPLETE-ARCHIVE \
  --output SIDECAR.update.json
python3 -B build/updates/generate.py --matrix windows.update.json intel.update.json arm.update.json \
  --output UPDATE-MATRIX.json
python3 -B -m unittest discover -s tests/updates -v
```

The generator validates the actual archive, refuses output inside the payload or overwriting
metadata, and uses the archive's original BUILD-INFO identity. Optional `--policy`, `--channel`,
`--sign-key` and `--public-key` are build-time interfaces; enabling signing alone cannot
authorize an upgrade with the empty compatibility policy. The signing host needs OpenSSL
CLI and PHP OpenSSL; targets need only their trusted PHP verifier/runtime.

Existing D2/D3 archives were not rebuilt for this build-only amendment. Backfilled unsigned
sidecars are in ignored `build/updates/dist/`; their identities describe the old payloads,
not the amended working source. Old D2 Windows and D3 Macs deliberately fail common-matrix
aggregation. See DEPLOYMENT-D3.md for exact identities, preserved hashes and executed tests.

D5 still owns background checks/downloads, authenticated discovery, admin-approved
installation, writer fencing/drain, coherent backup, copy-based migrations, promotion,
failure/reboot recovery, rollback/security-state reconciliation and trust persistence.
