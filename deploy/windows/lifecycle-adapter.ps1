# Explicit D5A owned-service/task adapter. Native execution requires elevation.
param([Parameter(Mandatory=$true)][string]$Root,
 [ValidateSet('Snapshot','Quiesce','Stop','Verify','Access','Resume','Permissions')][string]$Action,
 [string]$ReceiptJson='{}')
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if ($env:OS -ne 'Windows_NT' -or -not (Test-VmsAdmin) -or -not (Test-VmsInstallFolder $Root)) { throw 'Native Windows administrator and valid local installation required.' }
$paths=Get-VmsPaths $Root
$receipt=$ReceiptJson | ConvertFrom-Json
$specs=@(Get-VmsServices $paths)
function Owned-Service([string]$Name) {
 if ($Name -notin $specs.Name -or -not (Test-VmsServiceOwned $paths $Name)) { throw 'Missing/unowned service. No process was killed.' }
 return Get-Service -Name $Name -ErrorAction Stop
}
function Owned-Task([string]$Name) {
 if ($Name -notin $script:VmsTaskNames) { throw 'Unexpected task receipt.' }
 $task=Get-ScheduledTask -TaskName $Name -ErrorAction Stop
 if (($task.Actions | Out-String) -notmatch [regex]::Escape($paths.Scripts)) { throw 'Task belongs to another installation.' }
 return $task
}
function Stop-Owned([string[]]$Names) {
 foreach ($name in $Names) {
  $service=Owned-Service $name
  if ($service.Status -ne 'Stopped') { Stop-Service $name -ErrorAction Stop;$service.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30)) }
 }
}
function Restore-Startup($Service) {
 $type=switch ($Service.mode) { 'Auto' {'Automatic'} 'Manual' {'Manual'} 'Disabled' {'Disabled'} default {throw 'Invalid startup receipt.'} }
 Set-Service $Service.name -StartupType $type
 if ($Service.mode -eq 'Auto') { Set-ItemProperty -LiteralPath ('HKLM:\SYSTEM\CurrentControlSet\Services\'+$Service.name) -Name DelayedAutoStart -Value ([int]$Service.delayed) }
}
switch ($Action) {
 'Snapshot' {
  $services=@(foreach ($spec in $specs) {
   $service=Owned-Service $spec.Name;$cim=Get-CimInstance Win32_Service -Filter ("Name='"+$spec.Name+"'")
   $delayed=(Get-ItemProperty -LiteralPath ('HKLM:\SYSTEM\CurrentControlSet\Services\'+$spec.Name) -Name DelayedAutoStart -ErrorAction SilentlyContinue).DelayedAutoStart
   @{name=$spec.Name;mode=$cim.StartMode;running=($service.Status -eq 'Running');delayed=[bool]$delayed}
  })
  $tasks=@(foreach ($name in $script:VmsTaskNames) { $task=Owned-Task $name;@{name=$name;enabled=[bool]$task.Settings.Enabled} })
  @{services=$services;tasks=$tasks} | ConvertTo-Json -Depth 6 -Compress
 }
 'Quiesce' {
  foreach ($task in $receipt.tasks) { $null=Owned-Task $task.name;Disable-ScheduledTask -TaskName $task.name | Out-Null;Stop-ScheduledTask -TaskName $task.name }
  foreach ($service in $receipt.services) { $null=Owned-Service $service.name;Set-Service $service.name -StartupType Disabled }
  Stop-Owned @('PHILCST-Devices','PHILCST-Detector');'{}'
 }
 'Stop' { Stop-Owned @('PHILCST-LiveView','PHILCST-Web','PHILCST-PHP1','PHILCST-PHP2','PHILCST-PHP3','PHILCST-PHP4');'{}' }
 'Verify' {
  foreach ($spec in $specs) { if ((Owned-Service $spec.Name).Status -ne 'Stopped') { throw 'An owned service remains running.' } }
  foreach ($task in $receipt.tasks) { $t=Owned-Task $task.name;if ($t.Settings.Enabled -or $t.State -eq 'Running') { throw 'Owned scheduled writer remains enabled/running.' } }
  foreach ($process in Get-CimInstance Win32_Process) {
   $packaged=$process.ExecutablePath -and $process.ExecutablePath.StartsWith($paths.Runtime+'\',[StringComparison]::OrdinalIgnoreCase)
   $standalone=$process.Name -match '^(python[0-9.w-]*|php(-cgi)?)\.exe$' -and $process.CommandLine -and $process.CommandLine.Replace('/','\').Contains($paths.App+'\') -and $process.CommandLine -match '(camera_service\.py|device_service\.py|detector_service\.py|[\\/]artisan)'
   if ($packaged -or $standalone) {
    if ([int]$process.ProcessId -ne [int]$env:PHILCST_LIFECYCLE_CONTROLLER_PID) { throw 'Owned runtime child remains; quiescence refused. Nothing unrelated killed.' }
   }
  }
  '{}'
 }
 'Access' {
  foreach ($name in @('PHILCST-PHP1','PHILCST-PHP2','PHILCST-PHP3','PHILCST-PHP4','PHILCST-LiveView','PHILCST-Web')) {
   $saved=@($receipt.services | Where-Object name -eq $name)[0];$null=Owned-Service $name
   if ($saved.running) { Set-Service $name -StartupType Manual;if ((Get-Service $name).Status -ne 'Running') { Start-Service $name } }
  };'{}'
 }
 'Resume' {
  foreach ($saved in $receipt.services) {
   $null=Owned-Service $saved.name
   if ($saved.running -and $saved.name -in @('PHILCST-Devices','PHILCST-Detector')) { Set-Service $saved.name -StartupType Manual;Start-Service $saved.name }
   Restore-Startup $saved
  }
  foreach ($saved in $receipt.tasks) { $null=Owned-Task $saved.name;if ($saved.enabled) { Enable-ScheduledTask -TaskName $saved.name | Out-Null } }
  '{}'
 }
 'Permissions' {
  $base=Join-Path $Root 'state\lifecycle'
  $digest=[Security.Cryptography.SHA1]::Create().ComputeHash([Text.Encoding]::Unicode.GetBytes('PHILCST-WEB'))
  $webSid='*S-1-5-80-'+((0..4 | ForEach-Object { [BitConverter]::ToUInt32($digest,$_ * 4) }) -join '-')
  Invoke-VmsNative -Exe icacls.exe -Arguments @($base,'/inheritance:r','/grant:r','*S-1-5-32-544:(OI)(CI)F','*S-1-5-18:(OI)(CI)F','*S-1-5-19:(OI)(CI)RX',($webSid+':(OI)(CI)RX')) -Quiet | Out-Null
  foreach ($child in @('coordination','delivery')) {
   Invoke-VmsNative -Exe icacls.exe -Arguments @((Join-Path $base $child),'/inheritance:r','/grant:r','*S-1-5-32-544:(OI)(CI)F','*S-1-5-18:(OI)(CI)F','*S-1-5-19:(OI)(CI)M') -Quiet | Out-Null
  }
  foreach ($path in @((Join-Path $Root 'runtime\python'),(Join-Path $Root 'app\deploy\lifecycle'))) {
   Invoke-VmsNative -Exe icacls.exe -Arguments @($path,'/grant:r',($webSid+':(OI)(CI)RX')) -Quiet | Out-Null
  }
  Invoke-VmsNative -Exe icacls.exe -Arguments @((Join-Path $Root 'app\deploy'),'/grant:r',($webSid+':RX')) -Quiet | Out-Null
  '{}'
 }
}
