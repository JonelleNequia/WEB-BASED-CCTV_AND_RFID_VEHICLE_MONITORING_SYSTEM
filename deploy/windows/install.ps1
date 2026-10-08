<#
PHILCST Vehicle Monitoring - sets up this PC after the installer copied the files.

Run by PHILCST-VMS-Setup.exe (as administrator). Safe to run again: it keeps
.env (APP_KEY, keys), the database and the snapshots; it only refreshes the
config files, services, tasks and firewall rules.

    powershell -ExecutionPolicy Bypass -File install.ps1 [-Root C:\PHILCST-VMS]
        [-ComputerName PHILCST-VMS] [-SetNetworkPrivate] [-AdminEmail admin@philcst.local]

Steps: VC++ runtime, web port (80, or 8080 when 80 is taken), php.ini and
Caddyfile, .env (new APP_KEY and API key), database (migrations, first
admin with a random password), caches, Windows services (NSSM), Task
Scheduler (scheduler every minute, backup every day), firewall (private
networks), no sleep while plugged in, optional computer name. At the end
config\install.json and config\install-result.txt say how to open it.
#>
param(
    [string]$Root = '',
    [string]$ComputerName = '',
    [switch]$SetNetworkPrivate,
    [string]$AdminEmail = 'admin@philcst.local'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not $Root) { $Root = Get-VmsRoot }
$Root = $Root.TrimEnd('\')
$paths = Get-VmsPaths $Root

New-Item -ItemType Directory -Force -Path $paths.Logs, $paths.Config | Out-Null
$logFile = Join-VmsPath $paths.Logs ('install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
Start-Transcript -LiteralPath $logFile | Out-Null

$result = [ordered]@{
    status       = 'failed'
    started_at   = (Get-Date).ToString('o')
    finished_at  = $null
    web_port     = $null
    urls         = @()
    local_url    = $null
    hostname     = $env:COMPUTERNAME
    admin_email  = $null
    admin_created = $false
    restart_needed = $false
    warnings     = @()
    error        = $null
    log          = $logFile
}
$adminPassword = $null

function Install-VmsVcRedist {
    # PHP and torch need the Microsoft Visual C++ 2015-2022 runtime (x64).
    $key = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
    $installed = Get-ItemProperty -Path $key -ErrorAction SilentlyContinue
    if ($installed -and $installed.Installed -eq 1 -and [int]$installed.Major -ge 14 -and [int]$installed.Minor -ge 40) {
        Write-VmsStep "Visual C++ runtime $($installed.Version) is already installed"
        return
    }
    Write-VmsStep 'Installing the Visual C++ runtime'
    # 3010 = installed, restart later; 1638 = a newer one is already there.
    Invoke-VmsNative -Exe $paths.VcRedist -Arguments @('/install', '/quiet', '/norestart') -OkCodes @(0, 1638, 3010) | Out-Null
}

function Install-VmsService {
    param($Service)
    $nssm = $paths.Nssm
    $existing = Get-Service -Name $Service.Name -ErrorAction SilentlyContinue
    if ($existing) {
        # An earlier install: stop it (if running) and install it again.
        if ($existing.Status -ne 'Stopped') {
            try { $existing.Stop() } catch { }
            Wait-VmsServiceStatus -Name $Service.Name -Status 'Stopped' | Out-Null
        }
        Invoke-VmsNative -Exe $nssm -Arguments @('remove', $Service.Name, 'confirm') -Quiet | Out-Null
        # Windows deletes a service only when nothing has it open (e.g. the Services window).
        for ($i = 0; $i -lt 20 -and (Get-Service -Name $Service.Name -ErrorAction SilentlyContinue); $i++) { Start-Sleep -Milliseconds 500 }
        if (Get-Service -Name $Service.Name -ErrorAction SilentlyContinue) {
            throw "The old service $($Service.Name) is still there. Close the Services window (services.msc) and run the installer again."
        }
    }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Service.Log) | Out-Null
    Invoke-VmsNative -Exe $nssm -Arguments @('install', $Service.Name, $Service.Exe) -Quiet | Out-Null
    $settings = @(
        @('AppParameters', (Format-VmsServiceParameters $Service.Arguments)),
        @('AppDirectory', $Service.Directory),
        @('DisplayName', $Service.Display),
        @('Description', $Service.Description),
        @('Start', 'SERVICE_AUTO_START'),
        @('ObjectName', 'LocalSystem'),
        @('AppStdout', $Service.Log),
        @('AppStderr', $Service.Log),
        @('AppStdoutCreationDisposition', '4'),
        @('AppStderrCreationDisposition', '4'),
        @('AppRotateFiles', '1'),
        @('AppRotateOnline', '1'),
        @('AppRotateBytes', '10485760'),
        @('AppExit', 'Default', 'Restart'),
        @('AppRestartDelay', '3000'),
        @('AppThrottle', '10000'),
        @('AppStopMethodConsole', '5000')
    )
    foreach ($setting in $settings) {
        Invoke-VmsNative -Exe $nssm -Arguments (@('set', $Service.Name) + $setting) -Quiet | Out-Null
    }
    if ($Service.Environment.Count -gt 0) {
        $pairs = @($Service.Environment.Keys | Sort-Object | ForEach-Object { "$_=$($Service.Environment[$_])" })
        Invoke-VmsNative -Exe $nssm -Arguments (@('set', $Service.Name, 'AppEnvironmentExtra') + $pairs) -Quiet | Out-Null
    }
}

function Register-VmsTask {
    param([string]$Name, [string[]]$Schedule, [string]$Command)
    $arguments = @('/Create', '/F', '/TN', $Name, '/RU', 'SYSTEM', '/RL', 'HIGHEST', '/TR', $Command) + $Schedule
    Invoke-VmsNative -Exe 'schtasks.exe' -Arguments $arguments -Quiet | Out-Null
}

try {
    if (-not (Test-VmsAdmin)) { throw 'Run this as administrator (the installer does).' }
    if (-not (Test-VmsInstallFolder $Root)) {
        throw "Install into a folder without spaces or special letters, e.g. C:\PHILCST-VMS (not $Root)."
    }
    foreach ($required in @($paths.Php, $paths.PhpCgi, $paths.Python, $paths.Caddy, $paths.Go2rtc, $paths.Nssm, $paths.Artisan)) {
        if (-not (Test-Path -LiteralPath $required)) { throw "Missing file: $required. Run the installer again." }
    }

    $build = if (Test-Path -LiteralPath $paths.BuildInfo) { (Get-Content -LiteralPath $paths.BuildInfo -Raw | ConvertFrom-Json).version } else { 'unknown' }
    Write-VmsStep "Installing PHILCST VMS $build into $Root"
    Stop-VmsServices $paths
    Install-VmsVcRedist

    # --- Web port
    $port = Select-VmsWebPort -IsFree { param($p) Test-VmsPortFree $p }
    $result.web_port = $port
    if ($port -ne 80) { $result.warnings += "Port 80 is used by another program on this PC, so the system uses port $port." }
    Write-VmsStep "Web port: $port"

    # --- php.ini and Caddyfile from the templates
    $templates = $paths.Config
    $phpIni = Expand-VmsTemplate -Text (Get-Content -LiteralPath (Join-VmsPath $templates 'php.ini.template') -Raw) -Values @{ INSTALL_DIR = $Root }
    [IO.File]::WriteAllText($paths.PhpIni, $phpIni)
    $caddy = Expand-VmsTemplate -Text (Get-Content -LiteralPath (Join-VmsPath $templates 'Caddyfile.template') -Raw) `
        -Values @{ INSTALL_DIR = (ConvertTo-VmsForwardSlash $Root); WEB_PORT = $port }
    [IO.File]::WriteAllText($paths.Caddyfile, $caddy)

    # --- .env: new keys on a new install; kept (APP_KEY!) on a reinstall
    $database = ConvertTo-VmsForwardSlash $paths.Database
    $python = ConvertTo-VmsForwardSlash $paths.Python
    if (-not (Test-Path -LiteralPath $paths.Env)) {
        Write-VmsStep 'Writing .env (new APP_KEY and API key)'
        $envText = Expand-VmsTemplate -Text (Get-Content -LiteralPath (Join-VmsPath $templates 'env.template') -Raw) -Values @{
            APP_KEY = (New-VmsAppKey); API_KEY = (New-VmsApiKey); WEB_PORT = $port; DATABASE = $database; PYTHON = $python
        }
    } else {
        Write-VmsStep 'Keeping .env (APP_KEY and keys); updating the port and paths'
        $envText = Get-Content -LiteralPath $paths.Env -Raw
        if (-not (Get-VmsEnvValue $envText 'APP_KEY')) { $envText = Set-VmsEnvValue $envText 'APP_KEY' (New-VmsAppKey) }
        if (-not (Get-VmsEnvValue $envText 'DETECTOR_API_KEY')) { $envText = Set-VmsEnvValue $envText 'DETECTOR_API_KEY' (New-VmsApiKey) }
        $envText = Set-VmsEnvValue $envText 'APP_URL' "http://127.0.0.1:$port"
        $envText = Set-VmsEnvValue $envText 'DB_DATABASE' $database
        $envText = Set-VmsEnvValue $envText 'DETECTOR_PYTHON' $python
        $envText = Set-VmsEnvValue $envText 'MONITORING_SERVICES' 'managed'
        $envText = Set-VmsEnvValue $envText 'DETECTOR_STREAM_PROXY' 'true'
    }
    # UTF-8 without a BOM (Windows PowerShell's Set-Content would add one).
    [IO.File]::WriteAllText($paths.Env, $envText)

    # --- Database
    $newDatabase = -not (Test-Path -LiteralPath $paths.Database)
    if ($newDatabase) { New-Item -ItemType File -Force -Path $paths.Database | Out-Null }
    Write-VmsStep 'Database: migrations'
    Invoke-VmsArtisan $paths @('config:clear') -Quiet | Out-Null
    Invoke-VmsArtisan $paths @('migrate', '--force') | Out-Null
    if ($newDatabase) {
        Write-VmsStep 'Database: gates and default settings'
        Invoke-VmsArtisan $paths @('db:seed', '--class=Database\Seeders\InstallSeeder', '--force') | Out-Null
    }
    $admin = (Invoke-VmsArtisan $paths @('system:first-admin', "--email=$AdminEmail", '--json') -Quiet | Select-Object -Last 1) | ConvertFrom-Json
    $result.admin_email = $admin.email
    $result.admin_created = [bool]$admin.created
    if ($admin.created) {
        $adminPassword = $admin.password
        [IO.File]::WriteAllText($paths.FirstAdmin, "PHILCST VMS first sign-in`r`nEmail: $($admin.email)`r`nPassword: $adminPassword`r`nChange the password after signing in, then delete this file.`r`n")
        # Administrators and SYSTEM only.
        Invoke-VmsQuiet -Exe 'icacls.exe' -Arguments @($paths.FirstAdmin, '/inheritance:r', '/grant:r', '*S-1-5-32-544:F', '*S-1-5-18:F')
    }

    Write-VmsStep 'Public files, detector settings, live view config, caches'
    Invoke-VmsArtisan $paths @('storage:link') -Quiet | Out-Null
    # Managed mode: these only write the detector's settings and go2rtc.yaml (the services run the programs).
    Invoke-VmsNative -Exe $paths.Php -Arguments @('-c', $paths.PhpIni, $paths.Artisan, 'detector:start') -OkCodes @(0, 1) -Quiet | Out-Null
    Invoke-VmsNative -Exe $paths.Php -Arguments @('-c', $paths.PhpIni, $paths.Artisan, 'go2rtc:start') -OkCodes @(0, 1) -Quiet | Out-Null
    if (-not (Test-Path -LiteralPath $paths.Go2rtcConfig)) { throw "The live view config was not written ($($paths.Go2rtcConfig))." }
    foreach ($command in 'config:cache', 'route:cache', 'view:cache') { Invoke-VmsArtisan $paths @($command) -Quiet | Out-Null }

    # --- Windows services
    foreach ($service in Get-VmsServices $paths) {
        Write-VmsStep "Service: $($service.Display)"
        Install-VmsService $service
    }
    Start-VmsServices $paths

    # --- Task Scheduler: Laravel scheduler every minute, backup every day at 02:00
    Write-VmsStep 'Scheduled tasks'
    Register-VmsTask -Name 'PHILCST VMS Scheduler' -Schedule @('/SC', 'MINUTE', '/MO', '1') -Command (Join-VmsPath $paths.Scripts 'lib' 'scheduler.cmd')
    Register-VmsTask -Name 'PHILCST VMS Backup' -Schedule @('/SC', 'DAILY', '/ST', '02:00') -Command (Join-VmsPath $paths.Scripts 'lib' 'daily-backup.cmd')

    # --- Firewall: private and domain networks only
    Write-VmsStep 'Firewall rules (private networks)'
    Get-NetFirewallRule -Group $script:VmsFirewallGroup -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    $rule = @{ Group = $script:VmsFirewallGroup; Direction = 'Inbound'; Action = 'Allow'; Profile = @('Private', 'Domain') }
    New-NetFirewallRule @rule -DisplayName "PHILCST VMS web (TCP $port)" -Protocol TCP -LocalPort $port | Out-Null
    New-NetFirewallRule @rule -DisplayName 'PHILCST VMS live view (TCP 8555)' -Protocol TCP -LocalPort 8555 | Out-Null
    New-NetFirewallRule @rule -DisplayName 'PHILCST VMS live view (UDP 8555)' -Protocol UDP -LocalPort 8555 | Out-Null
    # Camera search (ONVIF, UDP 3702 multicast), reader module search (UDP broadcast) and readers in client mode answer the bundled Python.
    New-NetFirewallRule @rule -DisplayName 'PHILCST VMS device search (UDP)' -Program $paths.Python -Protocol UDP | Out-Null
    New-NetFirewallRule @rule -DisplayName 'PHILCST VMS device search (TCP)' -Program $paths.Python -Protocol TCP | Out-Null

    if ($SetNetworkPrivate) {
        foreach ($netProfile in @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' })) {
            try {
                Set-NetConnectionProfile -InterfaceIndex $netProfile.InterfaceIndex -NetworkCategory Private
                Write-VmsStep "Network '$($netProfile.Name)' ($($netProfile.InterfaceAlias)) set to Private"
            } catch {
                $result.warnings += "Could not set the network '$($netProfile.Name)' to Private: $($_.Exception.Message)"
            }
        }
    }

    # --- Power: never sleep or hibernate while plugged in
    Write-VmsStep 'Power: no sleep while plugged in'
    foreach ($setting in 'standby-timeout-ac', 'hibernate-timeout-ac', 'disk-timeout-ac') {
        Invoke-VmsNative -Exe 'powercfg.exe' -Arguments @('/change', $setting, '0') -Quiet | Out-Null
    }

    # --- Optional computer name (opens as http://<name>.local after a restart)
    $hostname = $env:COMPUTERNAME
    if ($ComputerName -and $ComputerName -ne $env:COMPUTERNAME) {
        if ($ComputerName -notmatch '^[A-Za-z0-9-]{1,15}$' -or $ComputerName -match '^[0-9]+$') {
            $result.warnings += "Computer name '$ComputerName' is not valid (1-15 letters, digits or -); kept $($env:COMPUTERNAME)."
        } else {
            try {
                Rename-Computer -NewName $ComputerName -Force -ErrorAction Stop
                $hostname = $ComputerName
                $result.restart_needed = $true
                $result.warnings += "Restart the PC to use the new computer name $ComputerName."
            } catch {
                $result.warnings += "Could not rename the PC: $($_.Exception.Message)"
            }
        }
    }
    $result.hostname = $hostname

    # --- Web answers?
    Write-VmsStep 'Waiting for the web system'
    if (-not (Wait-VmsWeb -Port $port -Seconds 120)) {
        # Say which services are down, and keep the end of their logs in this install log.
        $down = @(foreach ($service in Get-VmsServices $paths) {
            $state = Get-Service -Name $service.Name -ErrorAction SilentlyContinue
            if (-not $state -or $state.Status -ne 'Running') { "$($service.Name) ($(if ($state) { $state.Status } else { 'missing' }))" }
            if (Test-Path -LiteralPath $service.Log) {
                Write-Host "--- end of $($service.Log)"
                Get-Content -LiteralPath $service.Log -Tail 15 -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "    $_" }
            }
        })
        $which = if ($down.Count -gt 0) { " Not running: $($down -join ', ')." } else { '' }
        throw "The web system did not answer on port $port.$which See $($paths.Logs)."
    }

    $result.local_url = Format-VmsUrl 'localhost' $port
    $result.urls = @((Format-VmsUrl ($hostname.ToLowerInvariant() + '.local') $port)) + @(Get-VmsLanAddresses | ForEach-Object { Format-VmsUrl $_ $port })
    [IO.File]::WriteAllText($paths.OpenUrl, "[InternetShortcut]`r`nURL=$($result.local_url)`r`n")
    $result.status = 'ok'
    Write-VmsStep 'Done'
} catch {
    $result.error = $_.Exception.Message
    Write-Host "FAILED: $($result.error)" -ForegroundColor Red
} finally {
    $result.finished_at = (Get-Date).ToString('o')
    [IO.File]::WriteAllText($paths.InstallInfo, ($result | ConvertTo-Json -Depth 4))

    # What the installer's last page shows (and the technician can open later).
    $lines = New-Object System.Collections.ArrayList
    if ($result.status -eq 'ok') {
        [void]$lines.Add('PHILCST Vehicle Monitoring is installed and running.')
        [void]$lines.Add('')
        [void]$lines.Add('Open it in Google Chrome on any PC or phone on the same network:')
        foreach ($url in $result.urls) { [void]$lines.Add("    $url") }
        [void]$lines.Add("On this PC: $($result.local_url)")
        [void]$lines.Add('(The number address can change when the router changes; the .local name does not.)')
        if ($adminPassword) {
            [void]$lines.Add('')
            [void]$lines.Add('First sign-in:')
            [void]$lines.Add("    Email:    $($result.admin_email)")
            [void]$lines.Add("    Password: $adminPassword")
            [void]$lines.Add("Change the password after signing in. It is also in $($paths.FirstAdmin) - delete that file afterwards.")
        }
    } else {
        [void]$lines.Add('The setup did not finish:')
        [void]$lines.Add("    $($result.error)")
        [void]$lines.Add('')
        [void]$lines.Add("Details: $logFile")
        [void]$lines.Add('Fix the problem and run the installer again (your data is kept).')
    }
    foreach ($warning in $result.warnings) { [void]$lines.Add(''); [void]$lines.Add("Note: $warning") }
    [IO.File]::WriteAllText((Join-VmsPath $paths.Config 'install-result.txt'), (($lines -join "`r`n") + "`r`n"))
    Stop-Transcript | Out-Null
}

if ($result.status -ne 'ok') { exit 1 }
exit 0
