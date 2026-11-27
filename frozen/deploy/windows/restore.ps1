# PHILCST D5B read-only plan and explicitly confirmed recovery.
# No arbitrary archive script or unconditional service restart is permitted.
param([string]$File='', [ValidateSet('Inspect','Plan','Restore','Recover','Accept','Resume','Status')][string]$Action='Plan',
 [string]$Operation='', [string]$ConfirmPlan='', [switch]$AcceptLaterDataLoss,
 [switch]$AcknowledgeKnownDefects, [ValidateSet('current','forward','rollback')][string]$Decision='current', [switch]$Yes)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if ($Yes) { throw 'A blanket -Yes cannot approve a D5B restore. Inspect the concrete plan and use -ConfirmPlan with -AcceptLaterDataLoss.' }
$paths=Get-VmsPaths (Get-VmsRoot)
if (-not (Test-VmsAdmin)) { throw 'Open an Administrator PowerShell and rerun this exact reviewed command.' }
if (-not (Test-VmsLifecycleEnabled $paths)) { throw 'Initialized installed D5A and D5B state are required.' }
$arguments=@('-B',(Join-VmsPath $paths.App 'deploy' 'lifecycle' 'recovery.py'),$Action.ToLowerInvariant(),'--root',$paths.Root)
if ($File) { $arguments+=@('--archive',$File) }
if ($Operation) { $arguments+=@('--operation',$Operation) }
if ($ConfirmPlan) { $arguments+=@('--confirm-plan',$ConfirmPlan) }
if ($AcceptLaterDataLoss) { $arguments+='--accept-later-data-loss' }
if ($AcknowledgeKnownDefects) { $arguments+='--acknowledge-known-defects' }
if ($Action -eq 'Recover') { $arguments+=@('--decision',$Decision) }
Invoke-VmsNative -Exe $paths.Python -Arguments $arguments | Out-Null
