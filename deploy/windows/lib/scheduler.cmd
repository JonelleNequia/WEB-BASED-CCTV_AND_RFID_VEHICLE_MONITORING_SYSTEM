@echo off
rem PHILCST VMS - Laravel scheduler, run every minute by Task Scheduler (as SYSTEM).
rem It finalizes RFID reads, rewrites the live view config when cameras change, etc.
set ROOT=%~dp0..\..\..\..
"%ROOT%\runtime\php\php.exe" -c "%ROOT%\config\php.ini" "%ROOT%\app\artisan" schedule:run >> "%ROOT%\logs\scheduler.log" 2>&1
