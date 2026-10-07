@echo off
rem PHILCST VMS - status (double-click). Runs status.ps1 next to this file.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0status.ps1" %*
echo.
pause
