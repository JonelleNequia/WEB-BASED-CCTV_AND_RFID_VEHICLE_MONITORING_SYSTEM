# Kept for compatibility; stop only verified services/processes belonging to this root.
param([Parameter(Mandatory = $true)][string]$Root)
$ErrorActionPreference = 'Stop'
. (Join-Path $Root 'app\deploy\windows\lib\common.ps1')
$paths = Get-VmsPaths $Root
foreach ($taskName in $script:VmsTaskNames) {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($task -and (($task.Actions | Out-String) -notmatch [regex]::Escape($paths.Scripts))) { throw 'Task belongs to another installation.' }
    if ($task) { Disable-ScheduledTask -TaskName $taskName | Out-Null }
}
Stop-VmsServices $paths
