<# PHILCST VMS - back up now (database, snapshots, APP_KEY) into app\storage\backups. #>
param([string]$To = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
$paths = Get-VmsPaths (Get-VmsRoot)
$arguments = @('backup:run')
if ($To) { $arguments += "--to=$To" }
Invoke-VmsArtisan $paths $arguments | Out-Null
