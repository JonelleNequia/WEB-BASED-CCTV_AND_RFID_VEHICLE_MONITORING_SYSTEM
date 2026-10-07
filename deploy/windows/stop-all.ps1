<# PHILCST VMS - stop every service (they start again with Windows, or with start-all). #>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
Assert-VmsAdmin -ScriptPath $PSCommandPath
Stop-VmsServices (Get-VmsPaths (Get-VmsRoot))
Write-VmsStep 'Stopped.'
