# Pure ownership check used only for the currently executing D5B backup task.
function Test-VmsRecoveryScheduledOwner($Paths, $Task, $Processes, [int]$ControllerPid, [bool]$Scheduled) {
 if (-not $Scheduled -or $Task.TaskName -ne 'PHILCST VMS Backup') { return $false }
 $script=Join-Path $Paths.Scripts 'lib\daily-backup.cmd'
 if (($Task.Actions | Out-String) -notmatch [regex]::Escape($script)) { return $false }
 $process=@($Processes | Where-Object ProcessId -eq $ControllerPid)
 if ($process.Count -ne 1) { return $false }
 $controller=$process[0]
 if (-not [string]::Equals($controller.ExecutablePath,$Paths.Python,[StringComparison]::OrdinalIgnoreCase)) { return $false }
 $code=Join-Path $Paths.App 'deploy\lifecycle\recovery.py'
 if ($controller.CommandLine -notmatch [regex]::Escape($code) -or $controller.CommandLine -notmatch '\bbackup\b' -or $controller.CommandLine -notmatch '--scheduled') { return $false }
 $parent=[int]$controller.ParentProcessId
 for ($i=0;$i -lt 5;$i++) {
  $ancestors=@($Processes | Where-Object ProcessId -eq $parent)
  if ($ancestors.Count -ne 1) { return $false }
  $ancestor=$ancestors[0]
  if ($ancestor.CommandLine -and $ancestor.CommandLine -match [regex]::Escape($script)) { return $true }
  $parent=[int]$ancestor.ParentProcessId
 }
 return $false
}
