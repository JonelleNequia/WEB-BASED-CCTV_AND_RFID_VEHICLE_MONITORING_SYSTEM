# Optional root-owned one-shot preparation task. No automatic installation.
param([Parameter(Mandatory=$true)][string]$Root,[switch]$Install)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not (Test-VmsAdmin)) {throw 'Native administrator required.'}
$receipt=Get-Content -LiteralPath (Join-Path $Root 'state\update-controller.json') -Raw | ConvertFrom-Json
$id=[guid]::Parse($receipt.id).ToString();$capsule=Join-Path (Join-Path $Root 'state\update-controller') $id
$python=Join-Path $capsule 'runtime\python\python.exe';$cli=Join-Path $capsule 'app\deploy\updates\cli.py'
$name='PHILCST VMS Updates'
if ($Install) {
 $existing=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
 if ($existing -and (($existing.Actions | Out-String) -notmatch [regex]::Escape($capsule))) {throw 'Unowned update task exists. Nothing replaced.'}
 $arguments='-I -B '+(ConvertTo-VmsArgument $cli)+' tick --root '+(ConvertTo-VmsArgument $Root)
 $action=New-ScheduledTaskAction -Execute $python -Argument $arguments
 $trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
 $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 40) -StartWhenAvailable
 Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
} else { Invoke-VmsNative -Exe $python -Arguments @('-I','-B',$cli,'tick','--root',$Root) | Out-Null }
