# D5C installs only authenticated complete candidates through the retained controller.
param([ValidateSet('Status','Check','Import','Plan','Install','Recover','Resume')][string]$Action='Status',
 [string]$Root='', [string]$Candidate='', [string]$Manifest='', [string]$Package='',
 [string]$Confirmation='', [string]$Request='', [string]$Operation='',
 [ValidateSet('current','forward','rollback')][string]$Decision='forward', [switch]$MaintenanceConfirmed)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not (Test-VmsAdmin)) {throw 'Open PowerShell as Administrator and rerun this exact reviewed command.'}
if (-not $Root) {$Root=if ((Split-Path $PSScriptRoot -Leaf) -eq 'Controls') {Split-Path $PSScriptRoot -Parent} else {Get-VmsRoot}}
$receipt=Get-Content -LiteralPath (Join-Path $Root 'state\update-controller.json') -Raw | ConvertFrom-Json
$id=[guid]::Parse($receipt.id).ToString()
$controller=Join-Path (Join-Path $Root 'state\update-controller') $id
$args2=@('-B',(Join-Path $controller 'app\deploy\updates\cli.py'),$Action.ToLowerInvariant(),'--root',$Root)
foreach ($pair in @(@('--candidate',$Candidate),@('--manifest',$Manifest),@('--package',$Package),@('--confirmation',$Confirmation),@('--request',$Request),@('--operation',$Operation))) {if ($pair[1]) {$args2+=$pair}}
if ($MaintenanceConfirmed) {$args2+='--maintenance-confirmed'}
if ($Action -eq 'Recover') {$args2+=@('--decision',$Decision)}
Invoke-VmsNative -Exe (Join-Path $controller 'runtime\python\python.exe') -Arguments $args2 | Out-Null
