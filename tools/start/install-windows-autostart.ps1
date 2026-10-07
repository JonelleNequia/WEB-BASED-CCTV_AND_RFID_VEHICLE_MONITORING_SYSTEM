<#
PHILCST Vehicle Monitoring: start the whole system when Windows starts.

Run once, in PowerShell opened with "Run as administrator":
    powershell -ExecutionPolicy Bypass -File tools\start\install-windows-autostart.ps1

What it does:
  1. A Task Scheduler task "PHILCST Vehicle Monitoring" that runs
     start-system.bat at startup (no one needs to sign in), hidden, and
     restarts it if it stops.
  2. Windows Firewall rules so guard PCs on the LAN can open the system:
     the web port (default 8000) and the live view (WebRTC, default 8555,
     TCP and UDP). The basic MJPEG port (8765) stays closed to other PCs.
  3. A rule for the system's Python (device service and detector) so the
     answers of cameras and readers reach it: ONVIF camera search (UDP
     multicast 3702), the reader module search (UDP broadcast) and readers
     in client mode. Private and domain networks only; no address is set,
     so it works on any router or school LAN.

Remove it again with:  -Uninstall
#>
param(
    [int]$WebPort = 8000,
    [int]$WebRtcPort = 8555,
    [switch]$AtLogon,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$TaskName = 'PHILCST Vehicle Monitoring'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Script = Join-Path $PSScriptRoot 'start-system.bat'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) {
    Write-Host 'Open PowerShell with "Run as administrator" and run this again.' -ForegroundColor Yellow
    exit 1
}

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Get-NetFirewallRule -DisplayName 'PHILCST *' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Write-Host 'Autostart and firewall rules removed.'
    exit 0
}

# php.exe by its full path: a startup task does not have the user's PATH.
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    Write-Host 'PHP was not found in PATH. Install PHP (or XAMPP) first.' -ForegroundColor Yellow
    exit 1
}

$action = New-ScheduledTaskAction -Execute 'cmd.exe' `
    -Argument "/c set PHP=$php&& set WEB_PORT=$WebPort&& `"$Script`"" -WorkingDirectory $Root
$trigger = if ($AtLogon) { New-ScheduledTaskTrigger -AtLogOn } else { New-ScheduledTaskTrigger -AtStartup }
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable
$principal = if ($AtLogon) {
    New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest
} else {
    New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
}

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "Task '$TaskName' added: the system starts $(if ($AtLogon) { 'when you sign in' } else { 'with Windows' })."

Get-NetFirewallRule -DisplayName 'PHILCST *' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName 'PHILCST web' -Direction Inbound -Protocol TCP -LocalPort $WebPort -Action Allow -Profile Private,Domain | Out-Null
New-NetFirewallRule -DisplayName 'PHILCST live view (TCP)' -Direction Inbound -Protocol TCP -LocalPort $WebRtcPort -Action Allow -Profile Private,Domain | Out-Null
New-NetFirewallRule -DisplayName 'PHILCST live view (UDP)' -Direction Inbound -Protocol UDP -LocalPort $WebRtcPort -Action Allow -Profile Private,Domain | Out-Null
$python = Join-Path $Root 'school-vehicle-monitoring-detector\.venv\Scripts\python.exe'
if (Test-Path $python) {
    New-NetFirewallRule -DisplayName 'PHILCST device search (UDP)' -Direction Inbound -Program $python -Protocol UDP -Action Allow -Profile Private,Domain | Out-Null
    New-NetFirewallRule -DisplayName 'PHILCST device search (TCP)' -Direction Inbound -Program $python -Protocol TCP -Action Allow -Profile Private,Domain | Out-Null
} else {
    Write-Host "Python environment not found at $python; create it first (DEPLOYMENT.md), then run this again." -ForegroundColor Yellow
}
Write-Host "Firewall: web port $WebPort, live view port $WebRtcPort (TCP/UDP) and device search open on private networks."

Start-ScheduledTask -TaskName $TaskName
Write-Host 'Started. Open http://localhost:'$WebPort' in a minute.'
