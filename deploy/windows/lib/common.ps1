<#
PHILCST Vehicle Monitoring - shared functions of the Windows scripts.

Dot-sourced by install.ps1, uninstall.ps1, start-all.ps1, status.ps1 and the
other scripts in deploy/windows. Runs on Windows PowerShell 5.1 (built into
Windows 10/11) and PowerShell 7. Keep this file ASCII-only: Windows
PowerShell 5.1 reads scripts without a BOM as ANSI.

Install layout (default C:\PHILCST-VMS, a folder without spaces):
    app\                    the Laravel app (this file: app\deploy\windows\lib)
    runtime\php|python|caddy|go2rtc|nssm
    config\                 php.ini, Caddyfile, install.json (written by install.ps1)
    logs\                   service logs (rotated by NSSM)
#>

Set-StrictMode -Version 2

$script:VmsServicePrefix = 'PHILCST-'
$script:VmsFirewallGroup = 'PHILCST VMS'
$script:VmsTaskNames = @('PHILCST VMS Scheduler', 'PHILCST VMS Backup')
$script:VmsPortCandidates = @(80, 8080, 8081, 8090)

function Join-VmsPath {
    # [IO.Path]::Combine works the same on Windows PowerShell 5.1 and 7 (Join-Path takes 2 parts on 5.1).
    param([Parameter(Mandatory = $true, Position = 0)][string]$Base, [Parameter(ValueFromRemainingArguments = $true)][string[]]$Parts)
    $all = @($Base) + @($Parts)
    return [IO.Path]::Combine([string[]]$all)
}

function Get-VmsRoot {
    param([string]$From = $PSScriptRoot)
    if ($env:PHILCST_VMS_ROOT) { return $env:PHILCST_VMS_ROOT.TrimEnd('\', '/') }
    $dir = (Resolve-Path -LiteralPath $From).Path
    while ($dir) {
        if (Test-Path -LiteralPath (Join-VmsPath $dir 'app' 'artisan')) { return $dir }
        $parent = Split-Path -Parent $dir
        if (-not $parent -or $parent -eq $dir) { break }
        $dir = $parent
    }
    throw "The PHILCST VMS folder was not found above $From (expected app\artisan)."
}

function Get-VmsPaths {
    param([Parameter(Mandatory = $true)][string]$Root)
    $app = Join-VmsPath $Root 'app'
    return [pscustomobject]@{
        Root         = $Root
        App          = $app
        Artisan      = Join-VmsPath $app 'artisan'
        Env          = Join-VmsPath $app '.env'
        Database     = Join-VmsPath $app 'database' 'database.sqlite'
        Storage      = Join-VmsPath $app 'storage'
        AppLogs      = Join-VmsPath $app 'storage' 'logs'
        Backups      = Join-VmsPath $app 'storage' 'backups'
        Detector     = Join-VmsPath $app 'school-vehicle-monitoring-detector'
        Go2rtcDir    = Join-VmsPath $app 'storage' 'app' 'go2rtc'
        Go2rtcConfig = Join-VmsPath $app 'storage' 'app' 'go2rtc' 'go2rtc.yaml'
        CameraStatus = Join-VmsPath $app 'storage' 'app' 'camera' 'camera_status.json'
        DeviceStatus = Join-VmsPath $app 'storage' 'app' 'devices' 'device_service_status.json'
        Scripts      = Join-VmsPath $app 'deploy' 'windows'
        Runtime      = Join-VmsPath $Root 'runtime'
        Php          = Join-VmsPath $Root 'runtime' 'php' 'php.exe'
        PhpCgi       = Join-VmsPath $Root 'runtime' 'php' 'php-cgi.exe'
        Python       = Join-VmsPath $Root 'runtime' 'python' 'python.exe'
        Caddy        = Join-VmsPath $Root 'runtime' 'caddy' 'caddy.exe'
        Go2rtc       = Join-VmsPath $Root 'runtime' 'go2rtc' 'go2rtc.exe'
        Nssm         = Join-VmsPath $Root 'runtime' 'nssm' 'nssm.exe'
        VcRedist     = Join-VmsPath $Root 'runtime' 'vc_redist.x64.exe'
        Config       = Join-VmsPath $Root 'config'
        PhpIni       = Join-VmsPath $Root 'config' 'php.ini'
        Caddyfile    = Join-VmsPath $Root 'config' 'Caddyfile'
        InstallInfo  = Join-VmsPath $Root 'config' 'install.json'
        FirstAdmin   = Join-VmsPath $Root 'config' 'first-admin.txt'
        Logs         = Join-VmsPath $Root 'logs'
        OpenUrl      = Join-VmsPath $Root 'Open PHILCST VMS.url'
        BuildInfo    = Join-VmsPath $Root 'BUILD-INFO.json'
    }
}

<#
The Windows services (NSSM). Order = start order. Each one restarts by
itself when it stops, writes its output to a log that NSSM rotates, and runs
as LocalSystem (the device service needs admin rights for the reader
workaround; the web app writes the same files).
There is no queue worker: the app has no queued jobs (QUEUE_CONNECTION=sync).
#>
function Get-VmsServices {
    param([Parameter(Mandatory = $true)]$Paths)
    $python = @{ PYTHONUNBUFFERED = '1'; PYTHONIOENCODING = 'utf-8'; YOLO_OFFLINE = '1' }
    $list = New-Object System.Collections.ArrayList
    foreach ($n in 1..4) {
        [void]$list.Add([pscustomobject]@{
            Name = "PHILCST-PHP$n"; Display = "PHILCST VMS - PHP worker $n"
            Description = "Runs the web app's PHP (FastCGI on 127.0.0.1:$(9000 + $n)) for the web server."
            Exe = $Paths.PhpCgi; Arguments = @('-b', "127.0.0.1:$(9000 + $n)", '-c', $Paths.PhpIni); Directory = $Paths.App
            Environment = @{ PHP_FCGI_MAX_REQUESTS = '1000' }; Log = Join-VmsPath $Paths.Logs "php-$n.log"
        })
    }
    [void]$list.Add([pscustomobject]@{
        Name = 'PHILCST-Web'; Display = 'PHILCST VMS - Web server'
        Description = 'Caddy: the system in the browser (http://this-pc/) for every PC on the LAN.'
        Exe = $Paths.Caddy; Arguments = @('run', '--config', $Paths.Caddyfile, '--adapter', 'caddyfile'); Directory = $Paths.Root
        Environment = @{}; Log = Join-VmsPath $Paths.Logs 'web.log'
    })
    [void]$list.Add([pscustomobject]@{
        Name = 'PHILCST-LiveView'; Display = 'PHILCST VMS - Live view'
        Description = "go2rtc: passes each gate camera's main stream to the browser (WebRTC)."
        Exe = $Paths.Go2rtc; Arguments = @('-config', $Paths.Go2rtcConfig); Directory = $Paths.Go2rtcDir
        Environment = @{}; Log = Join-VmsPath $Paths.AppLogs 'go2rtc.log'
    })
    [void]$list.Add([pscustomobject]@{
        Name = 'PHILCST-Devices'; Display = 'PHILCST VMS - Cameras and RFID readers'
        Description = 'Finds cameras and UHF readers on the LAN and keeps the gate readers connected.'
        Exe = $Paths.Python; Arguments = @('device_service.py'); Directory = $Paths.Detector
        Environment = $python; Log = Join-VmsPath $Paths.AppLogs 'device-service.stdout.log'
    })
    [void]$list.Add([pscustomobject]@{
        Name = 'PHILCST-Detector'; Display = 'PHILCST VMS - Vehicle detector'
        Description = 'Watches the gate cameras: vehicles, plates and IN/OUT crossings.'
        Exe = $Paths.Python; Arguments = @('camera_service.py'); Directory = $Paths.Detector
        Environment = $python; Log = Join-VmsPath $Paths.AppLogs 'detector-runtime.log'
    })
    return $list.ToArray()
}

# ---------- pure helpers (tested on any OS: tests/windows) ----------

function Select-VmsWebPort {
    # The first free port of the candidates (80 first; IIS or Skype often hold it).
    param([int[]]$Candidates = $script:VmsPortCandidates, [Parameter(Mandatory = $true)][scriptblock]$IsFree)
    foreach ($port in $Candidates) {
        if (& $IsFree $port) { return $port }
    }
    throw "None of the web ports $($Candidates -join ', ') is free. Close the program that uses port 80 (often IIS) and run the installer again."
}

function New-VmsSecret {
    param([int]$Bytes = 32)
    $buffer = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return $buffer
}

function New-VmsAppKey { return 'base64:' + [Convert]::ToBase64String((New-VmsSecret 32)) }

function New-VmsApiKey { return (-join ((New-VmsSecret 24) | ForEach-Object { $_.ToString('x2') })) }

function Expand-VmsTemplate {
    # {{NAME}} placeholders; Windows tools read the result, so CRLF line ends.
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $true)][hashtable]$Values)
    foreach ($key in $Values.Keys) { $Text = $Text.Replace('{{' + $key + '}}', [string]$Values[$key]) }
    if ($Text -match '\{\{[A-Z_]+\}\}') { throw "Template value missing: $($Matches[0])" }
    return ($Text -replace "`r?`n", "`r`n")
}

function Set-VmsEnvValue {
    # KEY=value in .env text: replaced where it is, else added at the end.
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Text, [Parameter(Mandatory = $true)][string]$Key, [Parameter(Mandatory = $true)][AllowEmptyString()][string]$Value)
    $line = "$Key=$Value"
    $pattern = '(?m)^' + [regex]::Escape($Key) + '=.*$'
    if ([regex]::IsMatch($Text, $pattern)) {
        return [regex]::Replace($Text, $pattern, { param($m) $line })
    }
    if ($Text -and -not $Text.EndsWith("`n")) { $Text += "`r`n" }
    return $Text + $line + "`r`n"
}

function Get-VmsEnvValue {
    param([AllowEmptyString()][string]$Text, [string]$Key)
    $m = [regex]::Match($Text, '(?m)^' + [regex]::Escape($Key) + '=(.*?)\s*$')
    if ($m.Success) { return $m.Groups[1].Value.Trim('"') }
    return $null
}

function ConvertTo-VmsForwardSlash { param([string]$Path) return $Path.Replace('\', '/') }

function Test-VmsInstallFolder {
    # Spaces and non-ASCII letters break the tools' command lines; keep it simple.
    param([string]$Path)
    return ($Path -match '^[A-Za-z]:\\[A-Za-z0-9_.\-\\]*$') -and ($Path -notmatch '\s')
}

function Select-VmsLanAddresses {
    # IPv4 addresses other PCs can use: not loopback, not "no DHCP answer"
    # (169.254), not virtual adapters (Hyper-V, WSL, VirtualBox, VPN).
    param([object[]]$Addresses)
    $virtual = 'vEthernet|Loopback|VirtualBox|VMware|Hyper-V|WSL|Tailscale|ZeroTier|Bluetooth|TAP|VPN'
    return @($Addresses | Where-Object {
            $_.IPAddress -and $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and $_.InterfaceAlias -notmatch $virtual
        } | Sort-Object @{ Expression = { if ($_.InterfaceAlias -match 'Ethernet') { 0 } else { 1 } } }, InterfaceAlias |
        ForEach-Object { $_.IPAddress })
}

function Format-VmsUrl {
    param([string]$HostName, [int]$Port)
    if ($Port -eq 80) { return "http://$HostName/" }
    return "http://${HostName}:$Port/"
}

function Format-VmsServiceParameters {
    # NSSM AppParameters: quote arguments with spaces (install folders have none, but be safe).
    param([string[]]$Arguments)
    return (@($Arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' ')
}

function Hide-VmsSecrets {
    # Camera logins in URLs and keys in text that leaves the PC (collect-logs).
    param([AllowEmptyString()][string]$Text)
    $Text = [regex]::Replace($Text, '(?i)\b(rtsp|rtsps|http|https|onvif)://[^/\s:@]+:[^/\s@]*@', '$1://***:***@')
    $Text = [regex]::Replace($Text, '(?im)^(\s*(APP_KEY|DETECTOR_API_KEY|[A-Z_]*PASSWORD[A-Z_]*)\s*[=:]\s*).+$', '$1***')
    $Text = [regex]::Replace($Text, '(?i)("(password|source_password|python_api_key|api_key)"\s*:\s*)"[^"]*"', '$1"***"')
    return $Text
}

function Move-VmsData {
    # .env, the database files and storage\ go from one app folder to the other.
    param([string]$From, [string]$To)
    Move-Item -LiteralPath (Join-VmsPath $From '.env') -Destination (Join-VmsPath $To '.env') -Force
    foreach ($suffix in '', '-wal', '-shm') {
        $file = (Join-VmsPath $From 'database' 'database.sqlite') + $suffix
        if (Test-Path -LiteralPath $file) { Move-Item -LiteralPath $file -Destination ((Join-VmsPath $To 'database' 'database.sqlite') + $suffix) -Force }
    }
    $storage = Join-VmsPath $To 'storage'
    if (Test-Path -LiteralPath $storage) { Remove-Item -LiteralPath $storage -Recurse -Force }
    Move-Item -LiteralPath (Join-VmsPath $From 'storage') -Destination $storage
}

# ---------- Windows helpers ----------

function Test-VmsAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Assert-VmsAdmin {
    # Double-clicked .bat files: ask for admin rights once (UAC) and run again.
    param([string]$ScriptPath, [string[]]$Arguments = @())
    if (Test-VmsAdmin) { return }
    # Start-Process joins the arguments with spaces: quote the ones that have spaces.
    $quoted = (@($ScriptPath) + $Arguments) | ForEach-Object { if ($_ -match '\s' -and $_ -notmatch '^".*"$') { '"' + $_ + '"' } else { $_ } }
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File') + $quoted
    Start-Process -FilePath 'powershell.exe' -ArgumentList $argList -Verb RunAs
    exit 0
}

function Write-VmsStep {
    param([string]$Text)
    Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $Text)
}

function Invoke-VmsNative {
    # Run a program; throw with its output when it fails.
    param([Parameter(Mandatory = $true)][string]$Exe, [string[]]$Arguments = @(), [int[]]$OkCodes = @(0), [switch]$Quiet)
    # Windows PowerShell 5.1 turns a program's stderr into errors (fatal under
    # 'Stop'); the exit code decides here.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& $Exe @Arguments 2>&1 | ForEach-Object { "$_" })
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if (-not $Quiet -and $output) { $output | ForEach-Object { Write-Host "    $_" } }
    if ($OkCodes -notcontains $code) {
        throw "$([IO.Path]::GetFileName($Exe)) $($Arguments -join ' ') failed (exit $code): $($output -join ' ')"
    }
    return $output
}

function Invoke-VmsArtisan {
    param([Parameter(Mandatory = $true)]$Paths, [Parameter(Mandatory = $true)][string[]]$Arguments, [switch]$Quiet)
    $args2 = @('-c', $Paths.PhpIni, $Paths.Artisan) + $Arguments
    return Invoke-VmsNative -Exe $Paths.Php -Arguments $args2 -Quiet:$Quiet
}

function Get-VmsInstallInfo {
    param([Parameter(Mandatory = $true)]$Paths)
    if (Test-Path -LiteralPath $Paths.InstallInfo) {
        return Get-Content -LiteralPath $Paths.InstallInfo -Raw | ConvertFrom-Json
    }
    return $null
}

function Get-VmsWebPort {
    param([Parameter(Mandatory = $true)]$Paths)
    $info = Get-VmsInstallInfo $Paths
    if ($info -and $info.web_port) { return [int]$info.web_port }
    return 80
}

function Get-VmsInstalledServices {
    return @(Get-Service -Name ($script:VmsServicePrefix + '*') -ErrorAction SilentlyContinue)
}

function Start-VmsServices {
    param([Parameter(Mandatory = $true)]$Paths)
    foreach ($service in Get-VmsServices $Paths) {
        if (Get-Service -Name $service.Name -ErrorAction SilentlyContinue) {
            Write-VmsStep "Starting $($service.Display)"
            Start-Service -Name $service.Name -ErrorAction Continue
        }
    }
}

function Stop-VmsServices {
    param([Parameter(Mandatory = $true)]$Paths)
    $services = @(Get-VmsServices $Paths)
    [array]::Reverse($services)
    foreach ($service in $services) {
        $installed = Get-Service -Name $service.Name -ErrorAction SilentlyContinue
        if ($installed -and $installed.Status -ne 'Stopped') {
            Write-VmsStep "Stopping $($service.Display)"
            Stop-Service -Name $service.Name -Force -ErrorAction Continue
        }
    }
}

function Wait-VmsWeb {
    # True when http://127.0.0.1:<port>/up answers 200 (Laravel's health check).
    param([int]$Port, [int]$Seconds = 90)
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/up" -UseBasicParsing -TimeoutSec 5
            if ($response.StatusCode -eq 200) { return $true }
        } catch {
            Start-Sleep -Seconds 2
        }
    }
    return $false
}

function Get-VmsLanAddresses {
    $all = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.AddressState -eq 'Preferred' })
    return Select-VmsLanAddresses $all
}

function Test-VmsPortFree {
    param([int]$Port)
    return -not (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}
