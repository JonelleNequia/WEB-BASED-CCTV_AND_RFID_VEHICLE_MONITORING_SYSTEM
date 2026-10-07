@echo off
rem PHILCST VMS - gate kiosk shortcut (double-click). Runs gate-kiosk-shortcut.ps1 next to this file.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0gate-kiosk-shortcut.ps1" %*
echo.
pause
