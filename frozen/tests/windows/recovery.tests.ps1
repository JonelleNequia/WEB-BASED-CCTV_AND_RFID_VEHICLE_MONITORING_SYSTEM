# Pure ownership/entry-point fixtures; no native service/ACL/task mutation.
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../../deploy/windows/lib/recovery-task.ps1')
$count=0
function Check($Condition,[string]$Message) { if (-not $Condition) {throw $Message};$script:count++ }
$paths=@{Scripts='C:\PHILCST\scripts';Python='C:\PHILCST\runtime\python\python.exe';App='C:\PHILCST\app'}
$task=[pscustomobject]@{TaskName='PHILCST VMS Backup';Actions='C:\PHILCST\scripts\lib\daily-backup.cmd'}
$processes=@([pscustomobject]@{ProcessId=123;ParentProcessId=456;ExecutablePath=$paths.Python;CommandLine='python C:\PHILCST\app\deploy\lifecycle\recovery.py backup --scheduled --root C:\PHILCST'},[pscustomobject]@{ProcessId=456;ParentProcessId=0;CommandLine='cmd /c C:\PHILCST\scripts\lib\daily-backup.cmd'})
# Join-Path follows host separators; fake only that path join for a native Windows input contract.
function Join-Path([string]$Path,[string]$ChildPath) { return $Path.TrimEnd('\')+'\'+$ChildPath }
Check (Test-VmsRecoveryScheduledOwner $paths $task $processes 123 $true) 'Owned current controller not recognized.'
Check (-not (Test-VmsRecoveryScheduledOwner $paths $task $processes 123 $false)) 'Unselected scheduled mode accepted.'
Check (-not (Test-VmsRecoveryScheduledOwner $paths $task $processes 999 $true)) 'Unknown controller accepted.'
$processes[0].ExecutablePath='C:\untrusted\python.exe'
Check (-not (Test-VmsRecoveryScheduledOwner $paths $task $processes 123 $true)) 'Wrong interpreter accepted.'
$processes[0].ExecutablePath=$paths.Python;$processes[1].CommandLine='unrelated.cmd'
Check (-not (Test-VmsRecoveryScheduledOwner $paths $task $processes 123 $true)) 'Unrelated task ancestor accepted.'
$processes[1].CommandLine='cmd /c C:\PHILCST\scripts\lib\daily-backup.cmd';$task.TaskName='PHILCST VMS Scheduler'
Check (-not (Test-VmsRecoveryScheduledOwner $paths $task $processes 123 $true)) 'Scheduler exemption accepted.'
Remove-Item Function:Join-Path
$restore=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../../deploy/windows/restore.ps1') -Raw
Check ($restore -notmatch 'finally\s*\{[^}]*Start') 'Unconditional restart remains.'
Check ($restore -match '--confirm-plan' -and $restore -match '--accept-later-data-loss') 'Concrete approval is missing.'
$daily=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../../deploy/windows/lib/daily-backup.cmd') -Raw
Check ($daily -match 'recovery.py.*backup' -and $daily -match '--scheduled' -and $daily -notmatch 'artisan.*backup:run') 'Schedule bypasses shared engine.'
Write-Host "$count D5B pure Windows checks passed; native Windows NOT RUN."
