<#
PHILCST VMS - put a backup back: stops the services, restores the database,
snapshots and APP_KEY (the current data is backed up first), starts again.
    restore.bat                         (pick the backup file)
    restore.ps1 -File D:\PHILCST-backup-20261008-020000.zip [-Yes]
#>
param([string]$File = '', [switch]$Yes)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
$forward = @(); if ($File) { $forward += @('-File', $File) }; if ($Yes) { $forward += '-Yes' }
Assert-VmsAdmin -ScriptPath $PSCommandPath -Arguments $forward
$paths = Get-VmsPaths (Get-VmsRoot)

if (-not $File) {
    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Choose the PHILCST backup to restore'
    $dialog.InitialDirectory = $paths.Backups
    $dialog.Filter = 'PHILCST backups (PHILCST-backup-*.zip)|PHILCST-backup-*.zip|Zip files (*.zip)|*.zip'
    if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { Write-Host 'Nothing restored.'; exit 0 }
    $File = $dialog.FileName
}

Write-Host "Restore $File"
Write-Host 'This replaces the current data (database, snapshots). The current data is backed up first.'
if (-not $Yes -and (Read-Host 'Type YES to continue') -ne 'YES') { Write-Host 'Nothing restored.'; exit 0 }

Stop-VmsServices $paths
$failed = $false
try {
    Invoke-VmsArtisan $paths @('backup:restore', $File, '--force') | Out-Null
    # The cameras may differ: rewrite the detector settings and the live view config.
    Invoke-VmsArtisan $paths @('config:cache') -Quiet | Out-Null
    Invoke-VmsNative -Exe $paths.Php -Arguments @('-c', $paths.PhpIni, $paths.Artisan, 'detector:start') -OkCodes @(0, 1) -Quiet | Out-Null
    Invoke-VmsNative -Exe $paths.Php -Arguments @('-c', $paths.PhpIni, $paths.Artisan, 'go2rtc:start') -OkCodes @(0, 1) -Quiet | Out-Null
} catch {
    $failed = $true
    Write-Host "Restore failed: $($_.Exception.Message)" -ForegroundColor Red
} finally {
    Start-VmsServices $paths
}
if ($failed) { exit 1 }
Write-VmsStep 'Restored and running.'
