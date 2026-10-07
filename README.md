# WEB-BASED CCTV AND RFID VEHICLE MONITORING SYSTEM FOR PHILCST

Parking-focused, offline-first Laravel capstone prototype for PHILCST.

This version uses:

- RFID as the primary identifier for recurring registered vehicles
- CCTV/browser camera preview for guest monitoring, parking observation, and visual confirmation
- local-only storage and local database (no cloud dependency)

## System Direction (Current Build)

- Offline local deployment only
- Recurring registered vehicles use RFID state-based movement logic
- Guest vehicles are monitored through manual/CCTV-supported observation
- Camera pages remain available for live observation and calibration
- Legacy manual/CCTV event completion and weighted matching remain available for compatibility

## Core Operational Flow

1. Register recurring vehicle in `Vehicle Registry` with category and RFID tag.
2. Scan RFID from entrance/exit station or RFID desk.
3. System decides movement using current state:
   - `outside` -> next valid scan becomes `ENTRY`
   - `inside` -> next valid scan becomes `EXIT`
4. System updates vehicle parking state and daily counts.
5. RFID scan log + vehicle event log are created automatically.
6. Dashboard and logs update with latest registered activity.
7. Guest vehicles are encoded in `Guest Monitoring` (manual/CCTV-supported), separate from recurring RFID flow.

## Vehicle Categories

Registered vehicles now include:

- `parent`
- `student`
- `faculty_staff`
- `guard`
- `guest`

Business rule:

- RFID recurring workflow is for `parent`, `student`, `faculty_staff`, `guard`
- `guest` is intended for manual/CCTV guest monitoring

## Main Modules

- Login (single admin)
- Dashboard
- Live Monitoring
- Calibration
- Vehicle Logs
- Pending Details / Review Queue
- Vehicle Registry
- RFID Desk
- Guest Monitoring
- Settings
- Entrance Portal
- Exit Portal

## CCTV Positioning

CCTV/browser camera preview is positioned as:

- live observation
- guest monitoring support
- parking visibility
- visual confirmation/evidence

It is not positioned as the primary identifier for recurring registered vehicles.

## Database Updates in This Refactor

### `vehicles` (extended)

Added:

- `category`
- `current_state`
- `daily_count_date`
- `entries_today_count`
- `exits_today_count`
- `first_entry_today_at`
- `last_exit_today_at`
- `last_entry_at`
- `last_exit_at`
- `last_seen_at`

### `rfid_scan_logs` (extended)

Added:

- `resolved_event_type`
- `resulting_state`
- `vehicle_category`

### `vehicle_events` (extended)

Added:

- `vehicle_category`
- `resulting_state`
- `daily_entries_count`
- `daily_exits_count`

### `guest_vehicle_observations` (new)

Stores guest observation records:

- plate (optional)
- vehicle details
- location
- source (`manual` or `cctv`)
- observed time
- camera reference (optional)
- snapshot path (optional)
- notes

### Compatibility migration

Added safety migration to ensure `vehicle_events.event_status` exists in older local DBs:

- `2026_04_20_000050_ensure_event_status_column_on_vehicle_events_table.php`

## Offline Storage

All media and logs remain local:

- public local media for browser-visible images
- private local archive for RFID payload exports/backups
- central local storage config in `config/monitoring.php`

## Setup

## Requirements

- PHP 8.2+
- Composer
- MySQL (XAMPP) for local deployment target
- Browser with camera access for calibration/monitoring pages
- Optional: Python 3.10+ for detector bridge experiments

## Install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `.env` for local DB:

```env
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=philcst_vehicle_monitoring
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=file
FILESYSTEM_DISK=public
```

Detector models and go2rtc are not in git; fetch them once (checked by SHA-256):

```bash
php artisan runtime:fetch
```

Migrate + seed:

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

Run app:

```bash
php artisan serve
```

Open:

```text
http://127.0.0.1:8000
```

## Deployment (school PC / production)

Do these before the system is used at the gate. The demo settings above are
for development only.

Installing on the gate PC (start with Windows, firewall, live view, camera
video settings): see [DEPLOYMENT.md](DEPLOYMENT.md).

1. **Turn off debug mode.** In `.env`:

   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://<this-pc-lan-ip>:8000
   ```

   With `APP_DEBUG=true`, any error page shows code, file paths and `.env`
   values (database password, keys) to whoever opens it.

2. **Set the detector key.** Generate a key and put it in `.env`:

   ```bash
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```

   ```env
   DETECTOR_API_KEY=<the generated key>
   ```

   The Python detector gets it automatically through
   `storage/app/camera/camera_runtime_config.json`. A UHF/RFID adapter on the
   network must send it as the `X-Api-Key` header. Without a key, the API
   only answers this PC and only when `APP_ENV` is not `production`.

3. **Change the admin password** (`admin@philcst.local` / `password` is public).

4. **Apply migrations and cache the config:**

   ```bash
   php artisan migrate --force
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

   Run `php artisan config:clear` after every `.env` change (then cache again).

5. **Restart the detector** after changing `.env` so it uses the new key:
   stop `camera_service.py` and open any signed-in page.

6. **Keep private files private.** Camera frames and `camera_status.json` are
   written to `storage/app/camera/` and served only to signed-in users
   (`/camera/{role}/frame`, admin-only `/camera/status`). Serve only the
   `public/` folder from the web server; never the project root.

7. **Know the remaining open port.** The basic MJPEG stream on port `8765`
   has no login. Block it in the PC firewall for other devices (the
   full-quality live view goes through the signed-in pages and the WebRTC
   port 8555; `tools/start/install-windows-autostart.ps1` opens only that).

## Demo Login

- Email: `admin@philcst.local`
- Password: `password`

## Testing the New Parking Flow

1. Go to `/vehicle-registry`, register recurring vehicle + RFID tag.
2. Go to `/rfid-scans`, simulate scan.
3. Confirm:
   - automatic `ENTRY` or `EXIT` by current state
   - updated current state and counts
4. Go to `/dashboard` to verify parking summary updates.
5. Go to `/guest-observations` to record guest monitoring entries.
6. Go to `/monitoring` for dual feed + latest RFID context + guest observation stream.

## Legacy Compatibility Kept

The following remain for compatibility and staged development:

- manual ENTRY/EXIT creation
- pending-details completion flow
- weighted matching for manual/CCTV non-RFID exit flows
- active sessions for manual flow
- optional detector/runtime integration panel (advanced/dev section)

## Optional Python Module

The Python folder `school-vehicle-monitoring-detector` is still available for local experiments and future hardware integration readiness.

Normal parking workflow pages remain usable even when Python services are not running.

