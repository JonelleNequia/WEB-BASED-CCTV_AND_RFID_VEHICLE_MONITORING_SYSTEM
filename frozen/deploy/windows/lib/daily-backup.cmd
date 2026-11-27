@echo off
rem D5B scheduled backups use exactly the same owned, verified recovery contract.
rem Task remains disabled by default until native service/ACL acceptance.
set "ROOT=%~dp0..\..\..\.."
"%ROOT%\runtime\python\python.exe" -B "%ROOT%\app\deploy\lifecycle\recovery.py" backup --root "%ROOT%" --scheduled >> "%ROOT%\logs\backup.log" 2>&1
exit /b %errorlevel%
