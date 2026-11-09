# Pure D5A service definitions and bypass refusals. Never call native Windows APIs.
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../../deploy/windows/lib/common.ps1')
$work=Join-Path ([IO.Path]::GetTempPath()) ('philcst-d5a-ps-'+[guid]::NewGuid().ToString('N'))
$count=0
function Check($Condition,[string]$Message) { if (-not $Condition) { throw $Message };$script:count++ }
function Refuses([scriptblock]$Body) {
 try { & $Body } catch { if ($_.Exception.Message -match 'D5A|Maintenance persists') { $script:count++;return };throw }
 throw 'Uncertified service/lifecycle bypass was accepted.'
}
try {
 New-Item -ItemType Directory -Path (Join-Path $work 'state/lifecycle') -Force | Out-Null
 New-Item -ItemType Directory -Path (Join-Path $work 'app') -Force | Out-Null
 $paths=Get-VmsPaths $work
 [IO.File]::WriteAllText($paths.Env,'PHILCST_LIFECYCLE_ROOT="synthetic-retained-root"')
 $before=[IO.File]::ReadAllText($paths.Env)
 Check (Test-VmsLifecycleEnabled $paths) 'Persistent lifecycle was not detected.'
 $services=@(Get-VmsServices $paths)
 Check ($services.Count -eq 8) 'Owned service set changed.'
 foreach ($service in $services) {
  Check ($service.Exe -eq $paths.Python) 'Service bypasses installed startup gate.'
  Check (($service.Arguments -join ' ') -match 'service_host.py.+--service') 'Missing owned role startup gate.'
 }
 Check (($services | Where-Object Name -eq 'PHILCST-Web').Identity -eq 'NT SERVICE\PHILCST-Web') 'Private web SID changed.'
 Check (($services | Where-Object Name -eq 'PHILCST-Devices').Identity -eq 'LocalSystem') 'Device identity changed.'
 Check (($services | Where-Object Name -eq 'PHILCST-PHP1').Identity -eq 'NT AUTHORITY\LocalService') 'PHP identity changed.'
 foreach ($phase in @('quiescing','fenced','quiescent_pending','resuming','failed')) {
  [IO.File]::WriteAllText((Join-Path $work 'state/lifecycle/maintenance.json'),('{"schema":1,"phase":"'+$phase+'"}'))
  Refuses { Start-VmsServices $paths }
 }
 Refuses { Stop-VmsServices $paths }
 Refuses { Stop-VmsStrayProcesses $paths }
 Check ([IO.File]::ReadAllText($paths.Env) -eq $before) 'Definitions/refusals changed secrets/configuration.'
 # Even missing on-disk state cannot turn off detection while configuration points to it.
 Remove-Item (Join-Path $work 'state/lifecycle') -Recurse -Force
 Check (Test-VmsLifecycleEnabled $paths) 'Missing state silently disabled the installed fence.'
 Write-Host "$count D5A Windows pure checks passed; native services/ACLs/PS5.1 NOT RUN."
} finally { Remove-Item $work -Recurse -Force }
