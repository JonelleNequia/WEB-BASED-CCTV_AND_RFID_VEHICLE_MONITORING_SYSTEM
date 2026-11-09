<# PHILCST VMS - back up now (database, snapshots, APP_KEY) into app\storage\backups. #>
param([string]$To = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (Test-VmsLifecycleEnabled (Get-VmsPaths $(if (Get-Variable Root -ValueOnly -ErrorAction SilentlyContinue) { $Root } else { Get-VmsRoot }))) { throw 'Uncertified legacy lifecycle path is disabled for D5A; use the authorized later subphase.' }
$paths = Get-VmsPaths (Get-VmsRoot)
$arguments = @('backup:run')
if ($To) { $arguments += "--to=$To" }
Invoke-VmsArtisan $paths $arguments | Out-Null
