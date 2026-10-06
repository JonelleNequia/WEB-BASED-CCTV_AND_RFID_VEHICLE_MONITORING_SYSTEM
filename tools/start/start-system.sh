#!/bin/sh
# PHILCST Vehicle Monitoring: start everything on this Mac (development).
#   - the web system (php artisan serve) on WEB_PORT (default 8000)
#   - the live view service (go2rtc, bundled; WebRTC)
#   - the device service (UHF readers) and the vehicle detector (Python)
#   - the scheduler, which restarts any of them that stops (every minute)
# Ctrl+C stops the web system and the scheduler; the background services
# keep running (stop them from Activity Monitor or with `kill`).

cd "$(dirname "$0")/../.." || exit 1
WEB_PORT="${WEB_PORT:-8000}"
PHP="${PHP:-php}"

"$PHP" -v > /dev/null 2>&1 || { echo "PHP was not found. Install PHP or set PHP=/path/to/php."; exit 1; }

echo "Starting the live view service..."
"$PHP" artisan go2rtc:start
echo "Starting the device service and the detector..."
"$PHP" artisan devices:start
"$PHP" artisan detector:start

echo "Starting the web system on port $WEB_PORT..."
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" "$PHP" artisan serve --host=0.0.0.0 --port="$WEB_PORT" &
WEB_PID=$!
trap 'kill $WEB_PID 2>/dev/null' INT TERM EXIT

"$PHP" artisan schedule:work >> storage/logs/scheduler.log 2>&1
