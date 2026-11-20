# PHILCST D5B coherent backup. Use an installed trusted bundled controller only.
param([string]$To='', [string]$Operation='', [switch]$Initialize)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
$paths=Get-VmsPaths (Get-VmsRoot)
if (-not (Test-VmsAdmin)) { throw 'Open PowerShell as Administrator; supplied recovery arguments are never silently replaced during elevation.' }
if (-not (Test-VmsLifecycleEnabled $paths)) { throw 'D5B requires an initialized installed D5A foundation. Legacy backup is disabled.' }
$action=if ($Initialize) {'initialize'} else {'backup'}
$arguments=@('-B',(Join-VmsPath $paths.App 'deploy' 'lifecycle' 'recovery.py'),$action,'--root',$paths.Root)
if ($To) { $arguments+=@('--output',$To) }
if ($Operation) { $arguments+=@('--operation',$Operation) }
Invoke-VmsNative -Exe $paths.Python -Arguments $arguments | Out-Null
