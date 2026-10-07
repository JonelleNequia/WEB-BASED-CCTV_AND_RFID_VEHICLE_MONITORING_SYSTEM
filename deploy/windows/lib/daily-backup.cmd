@echo off
rem PHILCST VMS - daily backup at 02:00, run by Task Scheduler (as SYSTEM).
set ROOT=%~dp0..\..\..\..
"%ROOT%\runtime\php\php.exe" -c "%ROOT%\config\php.ini" "%ROOT%\app\artisan" backup:run >> "%ROOT%\logs\backup.log" 2>&1
