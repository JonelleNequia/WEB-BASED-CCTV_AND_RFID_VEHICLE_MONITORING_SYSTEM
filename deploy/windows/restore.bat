@echo off
rem PHILCST VMS - restore (double-click). Asks for administrator rights once,
rem then runs restore.ps1 next to this file.
net session >nul 2>&1
if errorlevel 1 (
    powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0restore.ps1" %*
echo.
pause
