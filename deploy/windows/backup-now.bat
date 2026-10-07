@echo off
rem PHILCST VMS - backup now (double-click). Runs backup-now.ps1 next to this file.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0backup-now.ps1" %*
echo.
pause
