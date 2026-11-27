# Manual complete delivery preparation

Build tooling only. No command here installs over working data or publishes.
Use a reviewed full commit and one semantic version/build identity for a release.
Current source has an externally created commit plus local changes; a frozen
**development** snapshot is used until explicitly reviewed/committed by its owner.
No build helper stages, commits, pushes or discards project files.

## One captured development source

Capture once using `build/windows/package.py snapshot` into a new private build
folder. All platform builders/helpers must execute from that exact SOURCE-INFO
snapshot. The common source hash covers application/build inputs together. Do not
combine old D2/D3 packages or independently changing working-tree builds.

Mac complete build recipe (builder only, native macOS/Xcode tools, Composer/PHP,
Node/npm and Python/pip; native target acceptance is separate):

```sh
python3 -B build/macos/build.py --arch arm64 --version 0.1.0-d6.4 --development --build-identity D6-local-20261127.4 --out build/macos/dist/D6-arm64 --cache PRIVATE_BUILD_CACHE
python3 -B build/macos/build.py --arch x86_64 --version 0.1.0-d6.4 --development --build-identity D6-local-20261127.4 --out build/macos/dist/D6-Intel --cache PRIVATE_BUILD_CACHE
```

For one already frozen source, execute its `build/macos/build.py` with the internal
`--frozen-source` path and the same arguments. Never edit a captured input; recapture
and rebuild affected candidates after source changes. Development versions include
the source hash suffix. Inspect actual artifact status in the D6 report before
assuming an output path exists. The newer arm64 PHP bottle graph is Sequoia-based;
Intel's historical PHP/Torch graph remains a production blocker.

Windows complete ZIP and EXE recipe requires a **native Windows x64** build host,
PowerShell7, Composer/PHP, Node/npm, Python and Inno. Use the frozen snapshot's
`build/windows/assemble.ps1 -SourceRoot SNAPSHOT -Version 0.1.0-d6.4
-BuildIdentity D6-local-20261127.4 -OutDir NEW_OUTPUT -CacheDir PRIVATE_CACHE
-Python python -Installer`. For a reviewed committed source, use
`build/windows/build-release.ps1 -SourceCommit FULL_SHA -Version VERSION
-BuildIdentity ID -DevelopmentSnapshot -Installer` on the clean checkout.
No Windows native ZIP/EXE is inferred from Mac parsing/layout tests.

Build clients from the same frozen snapshot using its `build/clients/build.py
--version 0.1.0-d6.4 --build-identity D6-local-20261127.4 --out NEW_CLIENT_OUTPUT`.
These ZIPs carry the same full source/version/build identity, plus their own helper
file hashes. They contain only public scripts/docs/manifests; no CA/root/private
key, runtime, DB, server service or collector.

## Review and acceptance

`build/delivery/review.py --inputs` accepts actual server `.update.json` sidecars
with their complete archives beside them, and client-only ZIPs. It verifies full
archive bytes/content/identity and client inventory, rejects mixed snapshots and
writes LOCAL-UPLOAD-INVENTORY.json, ACCEPTANCE.json and DRAFT-RELEASE-NOTES.md to a
new review folder. `--require-complete` fails a missing Windows/Intel/arm64/client
matrix while retaining the partial inventory for review. A source ZIP cannot pass
as a server archive. Nothing is uploaded/published by this tool.

Acceptance statuses are PASS, FAIL, NOT RUN, BLOCKED. Each native case carries the
artifact hash/source/platform/scope; concrete evidence records additionally bind
command, OS, physical-versus-fixture scope and evidence hash. `record` refuses an
artifact changed after its ledger. Never label a hosted internet/dev-tool runner
as clean offline native acceptance or a newer Mac as macOS14 acceptance.

## Manual GitHub workflow

After separate source approval/commit/push, manually dispatch **Manual offline
package candidates** with the exact full commit and version. It builds Windows,
arm64, Intel and clients with a common run/attempt identity. Action references are
pinned to full commits. No push trigger, write permission or publication step is
included. Review requires all legs/smoke checks successful; partial artifacts and
job failures remain visible. Download artifacts before the 14-day retention ends.
This task does not run that remote workflow.

Publisher certificates/private signing material and Mac Developer ID/notarization
credentials were not provided. Unsigned sidecars and checksums are review inputs,
not installation authentication. The existing update signer/index hooks require
protected external credentials; they are not exercised with production keys here.
Do not ship fixture keys or expand empty production upgrade/transfer policies.
