<#
PHILCST VMS - run by the installer before it copies files over an earlier
install: stops its services (waiting at most 30 s each), pauses its
scheduled tasks (install.ps1 registers them again) and ends any program of
the install that is still running, so no file is locked and no port taken.
Self-contained on purpose: the earlier install's own scripts may be older.
#>
param([Parameter(Mandatory = $true)][string]$Root)
$ErrorActionPreference = 'SilentlyContinue'

$services = @(Get-Service -Name 'PHILCST-*')
foreach ($service in $services) {
    if ($service.Status -ne 'Stopped') { try { $service.Stop() } catch { } }
}
foreach ($service in $services) {
    try { $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30)) } catch { }
}

foreach ($task in 'PHILCST VMS Scheduler', 'PHILCST VMS Backup') {
    $info = New-Object System.Diagnostics.ProcessStartInfo 'schtasks.exe', "/Change /TN `"$task`" /DISABLE"
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    try { [System.Diagnostics.Process]::Start($info).WaitForExit(15000) | Out-Null } catch { }
}

$runtime = (Join-Path $Root 'runtime').TrimEnd('\') + '\'
foreach ($process in @(Get-Process)) {
    $path = $null
    try { $path = $process.Path } catch { }
    if ($path -and $path.StartsWith($runtime, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-Process -Id $process.Id -Force
    }
}
exit 0
