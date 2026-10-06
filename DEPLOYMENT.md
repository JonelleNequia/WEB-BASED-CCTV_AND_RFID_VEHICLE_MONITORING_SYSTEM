# Deployment: school PC (Windows)

How to install the system on the gate PC so it starts by itself, works
offline, and shows a clear, low-delay live view. For development on a Mac,
see "Mac (development)" at the end.

## 1. What runs on the PC

| Part | What it does | Started by |
|---|---|---|
| Web system (`php artisan serve`) | The pages guards and admins use | `start-system.bat` |
| Live view service (go2rtc) | Passes each camera's main stream to the browser over WebRTC, not re-encoded | `php artisan go2rtc:start` and the scheduler |
| Device service (Python) | Finds cameras and readers; reads UHF tags | `php artisan devices:start` and the scheduler |
| Vehicle detector (Python) | Detection on each camera's sub stream; crossings, IN/OUT | `php artisan detector:start` and the scheduler |
| Scheduler (`php artisan schedule:work`) | Restarts any part that stopped (every minute) | `start-system.bat` |

go2rtc is bundled in `tools/go2rtc` (Windows and macOS). The first start
unpacks it into `storage/app/go2rtc` after checking its SHA-256, so no
internet connection is needed. Its settings file is written by the system
from the gates' cameras and rewritten when a camera's address or login
changes. Nobody edits it by hand.

## 2. Install (once)

1. Install PHP 8.2+ (XAMPP is fine), Composer and Python 3.10+.
2. Copy the project folder to the PC, then in a terminal in that folder:

   ```bat
   composer install --no-dev
   copy .env.example .env
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   ```

3. Python packages for the detector and the device service:

   ```bat
   cd school-vehicle-monitoring-detector
   python -m venv .venv
   .venv\Scripts\pip install -r requirements.txt
   cd ..
   ```

4. Production settings in `.env` (see README › Deployment for the reasons):

   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://<this-pc-lan-ip>:8000
   DETECTOR_API_KEY=<php -r "echo bin2hex(random_bytes(24));">
   ```

   Then `php artisan config:cache`. Change the admin password after the
   first sign-in.

## 3. Start with Windows

Open PowerShell with **Run as administrator** in the project folder:

```powershell
powershell -ExecutionPolicy Bypass -File tools\start\install-windows-autostart.ps1
```

This adds the Task Scheduler task **PHILCST Vehicle Monitoring** (runs
`tools\start\start-system.bat` when Windows starts, hidden, restarted if it
stops). It also opens these ports to the LAN (private networks only):

| Port | Used for |
|---|---|
| 8000 TCP | The web system |
| 8555 TCP and UDP | The live view video (WebRTC) |

The basic MJPEG view (port 8765) stays closed to other PCs.

Options: `-WebPort 8080`, `-WebRtcPort 8556`, `-AtLogon` (start when the
user signs in, instead of with Windows), `-Uninstall` (remove the task and
the firewall rules).

To start by hand instead, double-click `tools\start\start-system.bat` and
keep its window open.

Logs: `storage\logs` (`laravel.log`, `go2rtc.log`, `scheduler.log`,
`device-service*.log`, `detector-runtime.log`).

## 4. Cameras (live view)

- Add each camera in **Settings › Gates** (the wizard finds it on the
  network). The live view uses the camera's **main** stream; detection uses
  the **sub** stream in the background.
- WebRTC plays **H.264** only. In the camera's own settings page (TP-Link
  VIGI: *Settings › Video › Encoding*), set:

  | Setting | Main stream | Sub stream |
  |---|---|---|
  | Encoding | H.264 | H.264 |
  | Frame rate | 15–25 fps | 15 fps |
  | I-frame interval | 1–2 s (e.g. 25 at 25 fps) | 1–2 s |
  | Bitrate | 2048–4096 Kbps (VBR) | 512–1024 Kbps |
  | Smart Coding / H.264+ | Off | Off |

  The gate card shows a warning with these steps when a camera sends H.265.
- **Settings › Advanced › System status › Live view** shows how each gate is
  shown (WebRTC, HLS or basic), its picture size, frames per second and the
  delay in the browser.
- If WebRTC cannot connect (old browser, blocked port), the page uses HLS
  from go2rtc, then the basic MJPEG view from the detector, by itself.
- "Show detection boxes" on the Gate Monitor turns the boxes, zone and line
  over the video on or off (remembered on that browser).

## 5. Check after installing

1. Restart the PC; do not sign in. From another PC open
   `http://<this-pc-lan-ip>:8000` and sign in.
2. Gate Monitor: the badge on the video says **Live · full quality**.
3. Settings › Advanced › System status: Live view lists the gate with the
   camera's full size (e.g. 2560 × 1440) and about 25 frames / s.

## Mac (development)

```sh
tools/start/start-system.sh
```

Starts the same parts in this terminal (Ctrl+C stops the web system and the
scheduler). go2rtc runs from `storage/app/go2rtc`; `php artisan go2rtc:start`
starts it alone.
