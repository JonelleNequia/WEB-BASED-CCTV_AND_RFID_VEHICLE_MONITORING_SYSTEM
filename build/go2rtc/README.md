# D4 go2rtc viewer lease derivative

The pinned upstream is go2rtc 1.9.14, commit
`b5948cfb25404cc5cb37b166ecaa2dca20b11d4b`. Its sync output API authenticates signaling
upstream but has no consumer-expiry control. Its DELETE session support concerns ingest
producers, not HTTP output viewers. Closing a browser signaling request alone does not
revoke established peer media. See the version-specific
[upstream WebRTC server](https://github.com/AlexxIT/go2rtc/blob/v1.9.14/internal/webrtc/server.go).

`viewer-lease.patch` adds a fixed 30-second server-side close timer to output consumers
in both signaling paths. It neither changes camera/detection algorithms nor enables a new
public control API. D4's Laravel browser endpoint also refuses send-only/send-receive
producer offers. Authenticated browser reconnect is required after expiry. Browser player
renewal at 25 seconds avoids waiting for server expiry, but does not replace server enforcement.

`inputs.json` pins the complete upstream source archive, commit and patch hash and all three
build-host Go toolchain archives. `build.py` downloads only on the build machine, checks
hashes and upstream Go module checksums, builds native target architecture with CGO disabled,
records source/Go/dependency/binary identity and retains upstream and vendored module notices.
The toolchain/cache/vendor source never becomes a target requirement. Packaged manifests
cover the resulting binary and source/notices inventory. The runtime's version output also
marks the derivative/development build; it is not represented as the unmodified upstream.

Example (build machine only):

```sh
python3 -B build/go2rtc/build.py --cache PRIVATE_CACHE --out OUTPUT \
  --os darwin --arch arm64 --test
```

Other target pairs are `darwin/amd64` and `windows/amd64`. Native timer tests use two peers,
assert the compiled 30-second setting, and observe closure without browser cooperation.
A shorter timer fixture checks the same mechanism quickly. Cross-compilation is structural
build evidence, not native media/OS acceptance. The build currently supports Darwin and
Windows hosts; Linux is not a declared build host for these full packages.

Both Windows and Mac complete builders select this derivative and retain GO2RTC-SOURCE-INFO
and transitive license notices. Existing application development bundle hashes remain the
stock upstream pins; managed packaged services execute the derivative under runtime/go2rtc.
A D4 package must never substitute a stock/unleased binary and claim bounded WebRTC revocation.
The D3 update runtime compatibility fingerprint changes with these build inputs. Existing
D2/D3 archives are stale for D4 and are intentionally preserved, not relabeled.
