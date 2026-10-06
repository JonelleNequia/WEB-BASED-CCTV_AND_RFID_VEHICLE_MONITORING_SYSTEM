@echo off
rem PHILCST Vehicle Monitoring: start everything on this Windows PC.
rem   - the web system (php artisan serve) on WEB_PORT (default 8000)
rem   - the live view service (go2rtc, bundled; WebRTC)
rem   - the device service (UHF readers) and the vehicle detector (Python)
rem   - the scheduler, which restarts any of them that stops (every minute)
rem Double-click it, or let Task Scheduler run it at startup
rem (install-windows-autostart.ps1). Logs: storage\logs.

setlocal
cd /d "%~dp0..\.."
if "%WEB_PORT%"=="" set WEB_PORT=8000
if "%PHP%"=="" set PHP=php

"%PHP%" -v >nul 2>&1 || (
    echo PHP was not found. Install PHP or set PHP to the full path of php.exe.
    exit /b 1
)

echo Starting the live view service...
"%PHP%" artisan go2rtc:start
echo Starting the device service and the detector...
"%PHP%" artisan devices:start
"%PHP%" artisan detector:start

echo Starting the web system on port %WEB_PORT%...
start "PHILCST web" /min "%PHP%" artisan serve --host=0.0.0.0 --port=%WEB_PORT%

echo Running. Keep this window open (closing it stops the automatic restarts).
"%PHP%" artisan schedule:work >> storage\logs\scheduler.log 2>&1
