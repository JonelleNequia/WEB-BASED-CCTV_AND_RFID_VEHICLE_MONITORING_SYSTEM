<#
PHILCST VMS - one ZIP for troubleshooting, saved on the Desktop:
status, service logs, app logs, install/update logs, network and firewall
information. Passwords, keys and camera logins are removed; the database,
.env, go2rtc.yaml and the detector settings file are never included.
    collect-logs.bat      (send the ZIP to whoever helps you)
#>
param([string]$OutDir = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
$paths = Get-VmsPaths (Get-VmsRoot)
if (-not $OutDir) { $OutDir = [Environment]::GetFolderPath('Desktop') }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$work = Join-VmsPath ([IO.Path]::GetTempPath()) "philcst-logs-$stamp"
New-Item -ItemType Directory -Force -Path $work | Out-Null

function Save-Text {
    param([string]$Name, [AllowEmptyString()][string]$Text)
    [IO.File]::WriteAllText((Join-VmsPath $work $Name), (Hide-VmsSecrets $Text))
}

function Save-LogTail {
    # The end of each log (big logs: the last 20000 lines), secrets removed.
    param([string]$Folder, [string]$Prefix)
    if (-not (Test-Path -LiteralPath $Folder)) { return }
    Get-ChildItem -LiteralPath $Folder -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Extension -in '.log', '.txt' -or $_.Name -match '\.log\.\d+$' } |
        ForEach-Object {
            $lines = Get-Content -LiteralPath $_.FullName -Tail 20000 -ErrorAction SilentlyContinue
            Save-Text "$Prefix-$($_.Name)" (($lines -join "`r`n"))
        }
}

Write-VmsStep 'Collecting'
Save-Text 'status.json' ((& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-VmsPath $PSScriptRoot 'status.ps1') -Json) -join "`r`n")
Save-LogTail $paths.Logs 'logs'
Save-LogTail $paths.AppLogs 'app'
foreach ($file in $paths.InstallInfo, $paths.BuildInfo) {
    if (Test-Path -LiteralPath $file) { Save-Text ([IO.Path]::GetFileName($file)) (Get-Content -LiteralPath $file -Raw) }
}
Save-Text 'windows.txt' ((Get-CimInstance Win32_OperatingSystem | Format-List Caption, Version, BuildNumber, LastBootUpTime, FreePhysicalMemory | Out-String))
Save-Text 'services.txt' ((Get-Service -Name 'PHILCST-*' -ErrorAction SilentlyContinue | Format-Table Name, Status, StartType -AutoSize | Out-String))
Save-Text 'ipconfig.txt' ((& ipconfig.exe /all) -join "`r`n")
Save-Text 'network-profiles.txt' ((Get-NetConnectionProfile -ErrorAction SilentlyContinue | Format-List Name, InterfaceAlias, NetworkCategory, IPv4Connectivity | Out-String))
Save-Text 'firewall.txt' ((Get-NetFirewallRule -Group $script:VmsFirewallGroup -ErrorAction SilentlyContinue | Format-Table DisplayName, Enabled, Profile, Direction, Action -AutoSize | Out-String))
Save-Text 'ports.txt' ((Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -in 80, 8080, 8081, 8090, 1984, 8555, 8765, 9001, 9002, 9003, 9004 } | Format-Table LocalAddress, LocalPort, OwningProcess -AutoSize | Out-String))

$zip = Join-VmsPath $OutDir "PHILCST-logs-$($env:COMPUTERNAME)-$stamp.zip"
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory($work, $zip)
Remove-Item -LiteralPath $work -Recurse -Force
Write-VmsStep "Saved: $zip"
