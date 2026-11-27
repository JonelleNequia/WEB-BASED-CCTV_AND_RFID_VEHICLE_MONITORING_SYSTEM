<# PHILCST VMS - stop every service (they start again with Windows, or with start-all). #>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
Assert-VmsAdmin -ScriptPath $PSCommandPath
$managedPaths=Get-VmsPaths (Get-VmsRoot)
if (Test-VmsLifecycleEnabled $managedPaths) {
    Invoke-VmsNative -Exe $managedPaths.Python -Arguments @('-I','-B',(Join-Path $managedPaths.App 'deploy\lifecycle\operator_control.py'),'stop','--root',$managedPaths.Root) | Out-Null
    exit 0
}
Stop-VmsServices (Get-VmsPaths (Get-VmsRoot))
Write-VmsStep 'Stopped.'
