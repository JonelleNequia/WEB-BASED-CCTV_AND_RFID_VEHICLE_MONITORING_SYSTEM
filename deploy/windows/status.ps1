<#
PHILCST VMS - is everything running? Services, web, detector, device
service, disk space, last backup and the addresses to open.
    status.bat            (double-click; no admin rights needed)
    status.ps1 -Json      (for collect-logs and checks)
#>
param([switch]$Json)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
$paths = Get-VmsPaths (Get-VmsRoot)
$port = Get-VmsWebPort $paths
$problems = New-Object System.Collections.ArrayList

function Get-Age {
    param([string]$Timestamp)
    if (-not $Timestamp) { return $null }
    try { return [int]((Get-Date) - [datetime]::Parse($Timestamp)).TotalSeconds } catch { return $null }
}

function Read-JsonFile {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    try { return Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json } catch { return $null }
}

$services = @(foreach ($service in Get-VmsServices $paths) {
    $installed = Get-Service -Name $service.Name -ErrorAction SilentlyContinue
    $state = if ($installed) { [string]$installed.Status } else { 'Not installed' }
    if ($state -ne 'Running') { [void]$problems.Add("$($service.Display) is $state.") }
    [pscustomobject]@{ Service = $service.Display; Name = $service.Name; State = $state }
})

$web = $false
try { $web = (Invoke-WebRequest -Uri "http://127.0.0.1:$port/up" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } catch { $web = $false }
if (-not $web) { [void]$problems.Add("The web system does not answer on port $port.") }

$detector = Read-JsonFile $paths.CameraStatus
$detectorAge = if ($detector) { Get-Age ([string]$detector.updated_at) } else { $null }
$gates = @()
if ($detector -and $detector.cameras) {
    foreach ($property in $detector.cameras.PSObject.Properties) {
        $camera = $property.Value
        $detail = ''
        if ($camera.PSObject.Properties['last_error'] -and $camera.last_error) { $detail = Hide-VmsSecrets ([string]$camera.last_error) }
        $code = if ($camera.PSObject.Properties['error_code']) { [string]$camera.error_code } else { '' }
        $state = if ($camera.camera_running) { 'Connected' } elseif ($code -eq 'no_camera') { 'No camera yet' } else { 'Offline' }
        $gates += [pscustomobject]@{ Gate = $property.Name; Camera = $state; Detail = $detail }
    }
}
if ($null -eq $detectorAge -or $detectorAge -gt 30) { [void]$problems.Add('The detector has not reported for a while (or not yet).') }

$devices = Read-JsonFile $paths.DeviceStatus
$devicesAge = if ($devices -and $devices.PSObject.Properties['updated_at']) { Get-Age ([string]$devices.updated_at) } else { $null }
if ($null -eq $devicesAge -or $devicesAge -gt 30) { [void]$problems.Add('The device service has not reported for a while (or not yet).') }

$drive = Get-PSDrive -Name ($paths.Root.Substring(0, 1)) -ErrorAction SilentlyContinue
$freeGb = if ($drive) { [math]::Round($drive.Free / 1GB, 1) } else { $null }
if ($null -ne $freeGb -and $freeGb -lt 5) { [void]$problems.Add("Only $freeGb GB free on the install drive.") }

$lastBackup = Get-ChildItem -LiteralPath $paths.Backups -Filter 'PHILCST-backup-*.zip' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $lastBackup -or $lastBackup.LastWriteTime -lt (Get-Date).AddDays(-2)) { [void]$problems.Add('No backup in the last 2 days.') }

$addresses = @(Get-VmsLanAddresses)
$publicNetworks = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' })
foreach ($network in $publicNetworks) {
    [void]$problems.Add("The network '$($network.Name)' ($($network.InterfaceAlias)) is Public: other PCs cannot open the system. Set it to Private in Windows Settings.")
}

$build = Read-JsonFile $paths.BuildInfo
$report = [ordered]@{
    version = if ($build) { $build.version } else { $null }
    checked_at = (Get-Date).ToString('o')
    ok = ($problems.Count -eq 0)
    problems = @($problems)
    web = [ordered]@{ port = $port; answers = $web }
    services = $services
    detector_seconds_since_report = $detectorAge
    gates = $gates
    device_service_seconds_since_report = $devicesAge
    free_gb = $freeGb
    last_backup = if ($lastBackup) { $lastBackup.Name } else { $null }
    urls = @(@($env:COMPUTERNAME.ToLowerInvariant() + '.local') + $addresses | ForEach-Object { Format-VmsUrl $_ $port })
}

if ($Json) { $report | ConvertTo-Json -Depth 5; exit 0 }

Write-Host "PHILCST Vehicle Monitoring $($report.version)" -ForegroundColor Cyan
$services | Format-Table Service, State -AutoSize | Out-Host
Write-Host ("Web (port {0}): {1}" -f $port, $(if ($web) { 'answers' } else { 'NOT answering' }))
Write-Host ("Detector: {0}" -f $(if ($null -eq $detectorAge) { 'no report yet' } else { "last report $detectorAge s ago" }))
if ($gates) { $gates | Format-Table -AutoSize | Out-Host }
Write-Host ("Device service: {0}" -f $(if ($null -eq $devicesAge) { 'no report yet' } else { "last report $devicesAge s ago" }))
Write-Host ("Free space: {0} GB   Last backup: {1}" -f $freeGb, $report.last_backup)
Write-Host ''
Write-Host 'Open in Chrome:'
$report.urls | ForEach-Object { Write-Host "    $_" }
Write-Host ''
if ($problems.Count -eq 0) {
    Write-Host 'Everything is running.' -ForegroundColor Green
} else {
    Write-Host 'Needs attention:' -ForegroundColor Yellow
    $problems | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow }
}
