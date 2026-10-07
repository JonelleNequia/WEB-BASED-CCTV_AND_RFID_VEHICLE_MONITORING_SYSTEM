# Windows install kit: the bundle

`build-release.ps1` builds one ZIP with everything the gate PC needs. The
technician installs nothing else (no XAMPP, PHP, Python or Composer), and
the system runs offline.

```text
PHILCST-VMS/
  app/                         Laravel app (git HEAD) + vendor (composer --no-dev)
    school-vehicle-monitoring-detector/models/   yolov8n.pt, yolov8s.pt, easyocr/*.pth
  runtime/php/                 PHP 8.4 NTS x64 (php-cgi.exe for FastCGI)
  runtime/python/              embeddable Python 3.12 + all packages (requirements-windows.txt)
  runtime/caddy/caddy.exe      web server
  runtime/go2rtc/go2rtc.exe    live view (WebRTC)
  runtime/nssm/nssm.exe        Windows services
  runtime/vc_redist.x64.exe    Microsoft VC++ runtime (PHP and torch need it)
  config/                      php.ini / Caddyfile templates (the installer fills them in)
  BUILD-INFO.json              versions, commit, model hashes
```

## Build

On Windows (or in CI, `.github/workflows/windows-kit.yml` on `windows-latest`):

```powershell
pwsh build/windows/build-release.ps1
```

Output: `build/windows/dist/PHILCST-VMS-<date>-<commit>-win64.zip` and its `.sha256`.
Needs git, PHP 8.2+ with Composer, and Python 3 with pip. On macOS the same
script builds the folder (with `pip --platform win_amd64`) to check it; the
self-check of the Windows programs runs only on Windows.

Downloads are cached in `build/windows/cache/` and **every file is checked**
against `runtimes.json`: SHA-256 for each runtime and model, the Microsoft
signature for the VC++ runtime (its link always serves the newest build).
To update a runtime, change its version, URLs and hash together.

Nothing here is committed: `cache/` and `dist/` are ignored; releases go to
GitHub Releases.

## Why Caddy + php-cgi (not `php artisan serve`)

- `php artisan serve` is a development server: one request at a time and
  not meant to be exposed on a network.
- PHP has no php-fpm on Windows. `php-cgi.exe` speaks FastCGI but handles
  one request at a time, so the kit runs **four php-cgi services** (ports
  9001–9004) and Caddy shares the requests between them.
- Caddy is one signed exe without DLLs or other runtimes, its config is a
  short text file, it keeps long connections (live view, polling) open
  without tying up PHP, and NSSM runs it as a service. nginx would also work
  but needs more config and does not restart workers by itself.
- HTTP only, on the school LAN: no certificates to renew offline.

## Python

The embeddable Python reads only the paths in `python312._pth` (isolated
mode): its zip, `Lib\site-packages` and the detector folder. Packages are
the exact versions of `requirements-windows.txt` (the ones tested on the
development Mac); the PyPI torch wheels for Windows are CPU-only.

## Development (macOS)

The models and go2rtc are not in git. Fetch them once (checked by SHA-256):

```sh
php artisan runtime:fetch
```
