# Native Windows D4 adapter. Invoked only by the installed, elevated server helper.
param([Parameter(Mandatory=$true)][string]$Root,
 [ValidateSet('Inspect','Plan','Apply','Stop','Refresh','Start','Permissions','Trust')][string]$Action,
 [string]$ConfigJson='{}')
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
. (Join-Path $PSScriptRoot '..\clients\client-common.ps1')
. (Join-Path $PSScriptRoot 'lib\network-policy.ps1')
if ($env:OS -ne 'Windows_NT' -or -not (Test-VmsAdmin)) { throw 'Native Windows administrator execution required.' }
if (-not (Test-VmsInstallFolder $Root)) { throw 'Unsupported installation root.' }
$paths=Get-VmsPaths $Root
$data=$ConfigJson | ConvertFrom-Json
$identity=Get-VmsNetworkRuleIdentity $Root
$group=$identity.group;$names=$identity.names
function Assert-OwnedRules {
 foreach ($name in $names) {
  $rule=Get-NetFirewallRule -Name $name -ErrorAction SilentlyContinue
  if ($rule -and $rule.Group -ne $group) { throw 'Firewall rule name conflict. No unrelated rule was changed.' }
 }
}
function Stop-NetworkServices {
 foreach ($name in @('PHILCST-Web','PHILCST-LiveView','PHILCST-PHP1','PHILCST-PHP2','PHILCST-PHP3','PHILCST-PHP4')) {
  if (Get-Service $name -ErrorAction SilentlyContinue) {
   if (-not (Test-VmsServiceOwned $paths $name)) { throw 'Unowned service; no stop performed.' }
   Stop-Service $name -ErrorAction Stop
  }
 }
}
switch ($Action) {
 'Inspect' {
  $native=(Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Environment').PROCESSOR_ARCHITECTURE
  if ($native -ne 'AMD64') { throw 'Native Windows x64 server required.' }
  $interfaces=@(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } | ForEach-Object {
   $a=Get-NetAdapter -InterfaceIndex $_.InterfaceIndex -ErrorAction SilentlyContinue
   if ($a.Status -eq 'Up') { @{name=$_.InterfaceAlias;address=$_.IPAddress;prefix=$_.PrefixLength} }
  })
  $owned=@{}
  foreach ($process in Get-CimInstance Win32_Process) { if ($process.ExecutablePath -and $process.ExecutablePath.StartsWith($paths.Runtime+'\',[StringComparison]::OrdinalIgnoreCase)) { $owned[[int]$process.ProcessId]=$true } }
  foreach ($port in @(443,8080,1984,8555,8765,9001,9002,9003,9004)) {
   foreach ($listener in @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)) {
    if (-not $owned.ContainsKey([int]$listener.OwningProcess)) { throw "Required port $port belongs to another process. Nothing killed or reassigned." }
   }
  }
  foreach ($listener in @(Get-NetUDPEndpoint -LocalPort 8555 -ErrorAction SilentlyContinue)) { if (-not $owned.ContainsKey([int]$listener.OwningProcess)) { throw 'UDP 8555 belongs to another process.' } }
  @{interfaces=$interfaces} | ConvertTo-Json -Depth 5 -Compress
 }
 'Plan' {
  Assert-OwnedRules
  if ($data.config.mode -eq 'lan') {
   $profile=@(Get-NetConnectionProfile -InterfaceAlias $data.config.interface -ErrorAction Stop)
   if (-not $profile.Count -or @($profile | Where-Object { $_.NetworkCategory -notin @('Private','DomainAuthenticated') }).Count) { throw 'LAN sharing requires an administrator-selected Private/Domain interface. No network profile was changed.' }
  }
  @{rules=$(if ($data.config.mode -eq 'lan') { $names } else { @() });group=$group} | ConvertTo-Json -Compress
 }
 'Apply' {
  Assert-OwnedRules
  if ($null -eq $data.hosts_text) { throw 'Managed hosts plan is required.' }
  Write-ClientFile (Join-Path $env:SystemRoot 'System32\drivers\etc\hosts') $data.hosts_text
  $existing=@(foreach ($name in $names) { Get-NetFirewallRule -Name $name -ErrorAction SilentlyContinue })
  $plan=Get-VmsNetworkFirewallPlan $Root $data.config $existing
  foreach ($name in $plan.remove) { Remove-NetFirewallRule -Name $name }
  foreach ($rule in $plan.desired) {
   New-NetFirewallRule -Name $rule.name -DisplayName $rule.name -Group $rule.group -Direction Inbound -Action Allow -Profile $rule.profiles -InterfaceAlias $rule.interface -LocalAddress $rule.local_address -RemoteAddress $rule.remote_scope -Protocol $rule.protocol -LocalPort $rule.port -Program $rule.program | Out-Null
  }
  '{}'
 }
 'Stop' { Stop-NetworkServices; '{}' }
 'Refresh' {
  foreach ($command in @('config:clear','detector:start','go2rtc:start','config:cache')) {
   Invoke-VmsNative -Exe $paths.Php -Arguments @('-c',$paths.PhpIni,$paths.Artisan,$command) -OkCodes $(if ($command -in @('detector:start','go2rtc:start')) { @(0,1) } else { @(0) }) -Quiet | Out-Null
  }
  '{}'
 }
 'Start' {
  foreach ($name in @('PHILCST-PHP1','PHILCST-PHP2','PHILCST-PHP3','PHILCST-PHP4','PHILCST-LiveView','PHILCST-Web')) {
   if (Get-Service $name -ErrorAction SilentlyContinue) { if (-not (Test-VmsServiceOwned $paths $name)) { throw 'Unowned service.' };Start-Service $name }
  }
  '{}'
 }
 'Permissions' {
  # Caddy gets its own virtual service identity, never PHP's LocalService identity.
  $digest=[Security.Cryptography.SHA1]::Create().ComputeHash([Text.Encoding]::Unicode.GetBytes('PHILCST-WEB'))
  $sid='*S-1-5-80-'+((0..4 | ForEach-Object { [BitConverter]::ToUInt32($digest,$_ * 4) }) -join '-')
  Invoke-VmsNative -Exe icacls.exe -Arguments @($Root,'/grant:r',($sid+':RX')) -Quiet | Out-Null
  foreach ($path in @((Join-Path $Root 'app'),(Join-Path $Root 'runtime'),(Join-Path $Root 'state'))) { Invoke-VmsNative -Exe icacls.exe -Arguments @($path,'/grant:r',($sid+':RX')) -Quiet | Out-Null }
  foreach ($path in @((Join-Path $Root 'runtime\caddy'),(Join-Path $Root 'app\public'))) { Invoke-VmsNative -Exe icacls.exe -Arguments @($path,'/grant:r',($sid+':(OI)(CI)RX')) -Quiet | Out-Null }
  $web=Join-Path $Root 'state\web'
  Invoke-VmsNative -Exe icacls.exe -Arguments @($web,'/inheritance:r','/grant:r','*S-1-5-32-544:(OI)(CI)F','*S-1-5-18:(OI)(CI)F',($sid+':(OI)(CI)M')) -Quiet | Out-Null
  '{}'
 }
 'Trust' {
  $cert=Read-ClientRoot (Join-Path $Root 'state\public-root.crt') $data.sha256
  $store=Get-RootStore
  try {
   $present=@($store.Certificates | Where-Object { (Get-RootFingerprint $_) -eq $data.sha256 }).Count -gt 0
   if (-not $present) { $store.Add($cert) }
   $receipt=@{fingerprint=$data.sha256;root_added=(-not $present)}
   $oldFile=Join-Path $Root 'state\server-trust.json'
   if (Test-Path $oldFile) { $old=Get-Content $oldFile -Raw | ConvertFrom-Json;if ($old.fingerprint -eq $data.sha256 -and $old.root_added) { $receipt.root_added=$true } }
   Write-ClientFile $oldFile ($receipt | ConvertTo-Json)
  } finally { $store.Close() }
  '{}'
 }
}
