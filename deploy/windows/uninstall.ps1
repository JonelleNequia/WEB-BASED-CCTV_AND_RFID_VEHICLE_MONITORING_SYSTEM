<#
PHILCST Vehicle Monitoring - undoes install.ps1 before the uninstaller removes
the program files: stops and removes the Windows services, the scheduled
tasks and the firewall rules.

    powershell -ExecutionPolicy Bypass -File uninstall.ps1 [-Root C:\PHILCST-VMS] [-RemoveData]

Without -RemoveData the data stays in the install folder (database,
snapshots, backups, .env with APP_KEY, logs), so installing again brings
everything back. -RemoveData deletes them too (the uninstaller asks; the
default answer is No).
#>
param(
    [string]$Root = '',
    [switch]$RemoveData
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not $Root) { $Root = Get-VmsRoot }
$paths = Get-VmsPaths $Root.TrimEnd('\')

if (-not (Test-VmsAdmin)) { Write-Host 'Run this as administrator.'; exit 1 }

Stop-VmsServices $paths
foreach ($service in Get-VmsInstalledServices) {
    Write-VmsStep "Removing service $($service.Name)"
    if (Test-Path -LiteralPath $paths.Nssm) {
        Invoke-VmsQuiet -Exe $paths.Nssm -Arguments @('remove', $service.Name, 'confirm')
    } else {
        Invoke-VmsQuiet -Exe 'sc.exe' -Arguments @('delete', $service.Name)
    }
}

foreach ($task in $script:VmsTaskNames) {
    Invoke-VmsQuiet -Exe 'schtasks.exe' -Arguments @('/Delete', '/F', '/TN', $task)
}
Write-VmsStep 'Scheduled tasks removed'

Get-NetFirewallRule -Group $script:VmsFirewallGroup -ErrorAction SilentlyContinue | Remove-NetFirewallRule
Write-VmsStep 'Firewall rules removed'

# Files the installer did not copy (made while the system ran).
$generated = @($paths.PhpIni, $paths.Caddyfile, $paths.OpenUrl, (Join-VmsPath $paths.App 'bootstrap' 'cache'))
foreach ($item in $generated) { Remove-Item -LiteralPath $item -Recurse -Force -ErrorAction SilentlyContinue }
# public\storage is a link into storage\app\public: remove the link only.
$link = Join-VmsPath $paths.App 'public' 'storage'
if (Test-Path -LiteralPath $link) { Invoke-VmsQuiet -Exe 'cmd.exe' -Arguments @('/c', 'rmdir', $link) }

if ($RemoveData) {
    Write-VmsStep 'Removing the data (database, snapshots, backups, settings, logs)'
    $databaseFiles = @('', '-wal', '-shm') | ForEach-Object { $paths.Database + $_ }
    foreach ($item in @($paths.Env, $paths.Storage, $paths.Logs, $paths.Config) + $databaseFiles) {
        Remove-Item -LiteralPath $item -Recurse -Force -ErrorAction SilentlyContinue
    }
} else {
    Write-VmsStep "Data kept in $Root (database, snapshots, backups, .env). Install again to use it, or delete the folder."
}
exit 0
