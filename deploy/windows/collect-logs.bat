@echo off
rem PHILCST VMS - collect logs (double-click). Runs collect-logs.ps1 next to this file.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0collect-logs.ps1" %*
echo.
pause
