<# PHILCST VMS - restart every service. #>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
Assert-VmsAdmin -ScriptPath $PSCommandPath
$paths = Get-VmsPaths (Get-VmsRoot)
Stop-VmsServices $paths
Start-VmsServices $paths
if (Wait-VmsWeb -Port (Get-VmsWebPort $paths) -Seconds 60) { Write-VmsStep 'Running.' } else { Write-VmsStep 'The web system does not answer yet; run status.bat in a minute.' }
