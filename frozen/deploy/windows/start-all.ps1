<# PHILCST VMS - start every service (web, PHP workers, live view, devices, detector). #>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
Assert-VmsAdmin -ScriptPath $PSCommandPath
$managedPaths=Get-VmsPaths (Get-VmsRoot)
if (Test-VmsLifecycleEnabled $managedPaths) {
    Invoke-VmsNative -Exe $managedPaths.Python -Arguments @('-I','-B',(Join-Path $managedPaths.App 'deploy\lifecycle\operator_control.py'),'start','--root',$managedPaths.Root) | Out-Null
    exit 0
}
$paths = Get-VmsPaths (Get-VmsRoot)
Start-VmsServices $paths
if (Wait-VmsWeb -Port (Get-VmsWebPort $paths) -Seconds 60) { Write-VmsStep 'Running.' } else { Write-VmsStep 'The web system does not answer yet; run status.bat in a minute.' }
