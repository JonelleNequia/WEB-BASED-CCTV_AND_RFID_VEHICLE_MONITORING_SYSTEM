# WEB-BASED CCTV AND RFID VEHICLE MONITORING SYSTEM FOR PHILCST

Parking-focused, offline-first Laravel capstone prototype for PHILCST.

This version uses:

- Registered plate identification, with optional RFID for recurring vehicles
- CCTV/browser camera preview for guest monitoring, parking observation, and visual confirmation
- local-only storage and local database (no cloud dependency)

## System Direction (Current Build)

- Offline local deployment only
- Registered vehicles use known camera/staff-confirmed IN/OUT directions, with the existing RFID fallback when the camera is offline
- Guest vehicles are monitored through manual/CCTV-supported observation
- Camera pages remain available for live observation and calibration
- Legacy manual/CCTV event completion and weighted matching remain available for compatibility

## Core Operational Flow

1. Register a vehicle in `Vehicle Registry` with a unique valid plate, staff attribution and category. RFID is optional and can be assigned later.
2. Identify a camera crossing through reliable exact OCR, existing manual plate Review, or a genuine eligible RFID read.
3. Known IN/OUT sets occupancy chronologically. Unknown camera direction stays in Review and does not toggle state. The existing camera-offline RFID fallback uses the vehicle's state.
4. Each logical pass contributes one movement and its applicable occupancy effect; retries and late evidence share that occurrence.
5. Plate movements use a vehicle event without a synthetic RFID scan. Only actual RFID evidence creates a scan log.
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

## Gate management

Settings has **Gates** and **Advanced**. Rename gates and change their active
state on Gates, alongside camera, reader and calibration setup. Older
`/settings?tab=general` bookmarks redirect to Gates.

At least two non-archived gates are retained; inactive gates count toward
this minimum. An admin can remove a gate when more than two remain, after
confirmation. Removal archives the gate, stops its monitoring and releases
its device assignments. Devices, calibration, camera IDs, snapshots and
historical records are kept. Normal filters exclude archived choices; All gates
still includes their history, and supported old gate-filter bookmarks remain
available as read-only historical queries with Reset. Archived gates cannot be
selected for new records. Gate codes and IDs are never renumbered or reused.

The gate-management migration adds missing gates to installations with fewer
than two. These added gates are inactive and unconfigured; existing names,
devices, records and accounts are preserved. See DEPLOYMENT.md for upgrading
the Windows installation.

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

On a fresh installation, migrate and add required non-account seed data:

```bash
php artisan migrate --force
php artisan db:seed --class=InstallSeeder --force
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

3. **Provision the first administrator locally.** Follow [ACCOUNT-SECURITY.md](ACCOUNT-SECURITY.md).
   There are no default login credentials. Existing users sign in with their
   own current credentials and must choose a new personal password once.

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

## Account setup and activation

No public signup or demo login is provided. The local installer delivers a
protected, expiring first-admin setup code. After setup, admins manage individual
accounts under **Accounts**; owners activate using privately delivered codes.
See [ACCOUNT-SECURITY.md](ACCOUNT-SECURITY.md) for setup, migration and password policy.

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

Vehicle Color has been retired from active entry, review, logs, reports and
exports. Older detector color fields are accepted and ignored. Stored historical
values and backup/restore schemas are retained; no database migration is needed.
See [PHASE2-VEHICLE-COLOR.md](PHASE2-VEHICLE-COLOR.md) for compatibility and checks.

Existing Registry, Guests and Activity Logs list filters now update automatically
using local server requests. Search waits about 300 ms after typing; selected
filters, pagination, history and matching export links are retained. See
[PHASE3-AUTO-FILTERS.md](PHASE3-AUTO-FILTERS.md) for behavior and verification.

Activity Logs no longer defaults unknown/unassigned RFID scans to Registered.
Registration display uses vehicle relationships or verified historical category
receipts; readable plate text alone is not evidence. Historical stored values
are retained. See [PHASE4-CLASSIFICATION.md](PHASE4-CLASSIFICATION.md).

The Python folder `school-vehicle-monitoring-detector` is still available for local experiments and future hardware integration readiness.

New vehicle registrations require **Registered by**, a staff name separate from
the optional owner. Each record keeps its name, and the last successful name
becomes the installation-wide default for new registrations. Historical rows
remain usable without a backfill. This batch needs one additive migration; see
[REGISTRATION-PHASE1.md](REGISTRATION-PHASE1.md) for verification and update steps.

Registration Phase 2 shares plate normalization across registration, OCR,
correction and searches. Reliable exact matches can identify registered vehicles;
uncertain or ambiguous results stay in existing Review. Corrections retain the
same pass, images and audit. See
[REGISTRATION-PHASE2.md](REGISTRATION-PHASE2.md) for the decision table, additive
migration, compatibility notes and pending Windows update steps.

Registration Phase 3 makes RFID optional, adds chronological plate-based
occupancy, and supports later assignment/replacement on the same registration.
Lost UIDs become permanently **Lost / Unavailable**; new reads are ignored
without business scan/event records or operator alerts. Historical records and
the four known broken references are retained. See
[REGISTRATION-PHASE3.md](REGISTRATION-PHASE3.md) for decisions, changed files,
verification and the pending additive Windows migration. No historical occupancy
rebuild runs automatically.

Registration Phase 4 shares physical vehicle types across registration, editing,
manual entry and corrections. Electric types and Tricycle remain distinct.
Occurrences retain detector evidence, registry snapshots and staff corrections
separately, with type provenance in logs/reports. Installed models expose Car,
Motorcycle, Bus and Truck; fine-grained electric recognition is not claimed.
There is no Phase 4 migration. See [REGISTRATION-PHASE4.md](REGISTRATION-PHASE4.md).

Normal parking workflow pages remain usable even when Python services are not running.


Registration Phase 5 hides archived gates from normal filter choices while keeping
historical records and bookmarked historical queries available. Active user labels
now say Guest/Guests; integration codes and existing URLs are retained. Table row
menus use local overlays without enlarging table scroll areas. See
[REGISTRATION-PHASE5.md](REGISTRATION-PHASE5.md) for compatibility, verification,
Windows update steps and the Phase 6 handoff. No Phase 5 migration is required.

A targeted plate-review regression fix supersedes the older Phase 2 transposition
rule: `395ISX` stays literal and differs from `ISX395`. Corrections verify and
reuse the same occurrence event, preserve genuine RFID/history, and show safe
errors while retaining staff input. Historical plates are not rewritten. See
[REGRESSION-LITERAL-PLATE-RECONCILIATION.md](REGRESSION-LITERAL-PLATE-RECONCILIATION.md)
for incident evidence, compatibility, verification and update notes. No new
migration is required.

Registration Phase 6 attaches each camera occurrence's own snapshot to registered
RFID events as well as plate/Guest/Review records, without another Guest record
or movement. New crossing images and OCR crops use private occurrence storage and
authenticated image URLs. Matching retries share evidence; ordered clearer-image
updates preserve event identity and occupancy. Legacy/public-media and durable
detector-delivery limitations remain explicitly documented. See
[REGISTRATION-PHASE6.md](REGISTRATION-PHASE6.md) for ownership checks, failure
behavior, verification and pending Windows update steps. No Phase 6 migration or
historical image backfill is required.
