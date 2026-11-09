@echo off
rem PHILCST VMS - daily backup at 02:00, run by Task Scheduler (as SYSTEM).
set ROOT=%~dp0..\..\..\..
if exist "%ROOT%\state\lifecycle" (
 "%ROOT%\runtime\python\python.exe" -B "%ROOT%\app\deploy\lifecycle\service_host.py" --root "%ROOT%" --check-only
 if errorlevel 1 exit /b 75
)
"%ROOT%\runtime\php\php.exe" -c "%ROOT%\config\php.ini" "%ROOT%\app\artisan" backup:run >> "%ROOT%\logs\backup.log" 2>&1
