<# D2 local-only installation. Runs only after package/preflight validation. Existing data and secrets are retained; no certified D5 upgrade/rollback is implied. #>
param(
    [string]$Root = '',
    [string]$ComputerName = '',
    [switch]$SetNetworkPrivate,
    [string]$AdminEmail = 'admin@philcst.local',
    [string]$AdminName = 'Administrator',
    [switch]$InitializeSetup,
    [switch]$ReplaceSetupCode,
    [switch]$AllowDevelopmentCandidate,
    [string]$ExpectedPublisherThumbprint = '',
    [string]$NetworkConfig = ''
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not $Root) { $Root = Get-VmsRoot }
$Root = $Root.TrimEnd('\')
$paths = Get-VmsPaths $Root
if (Test-VmsLifecycleEnabled $paths) { throw 'D5A reinstall requires the later certified lifecycle path; nothing was provisioned.' }
if ($ComputerName -or $SetNetworkPrivate) { throw 'D4 never renames the computer or silently changes network profiles. Select LAN sharing through NetworkConfig.' }
$approvedPort = Assert-VmsInstallPreflight -Root $Root -Payload $Root -Installed -AllowDevelopmentCandidate:$AllowDevelopmentCandidate -ExpectedPublisherThumbprint $ExpectedPublisherThumbprint
$networkPreflight='import sys,json;from pathlib import Path;sys.path.insert(0,sys.argv[1]);import server;print(json.dumps(server.preflight_configuration(server.layout(Path(sys.argv[2]),windows=True),json.loads(Path(sys.argv[3]).read_text()) if sys.argv[3] else None)))'
Invoke-VmsNative -Exe $paths.Python -Arguments @('-B','-c',$networkPreflight,(Join-Path $paths.App 'deploy\network'),$Root,$NetworkConfig) -Quiet | Out-Null


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
$setupIssued = $false

function Install-VmsVcRedist {
    # PHP and torch need the Microsoft Visual C++ 2015-2022 runtime (x64).
    $key = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
    $installed = Get-ItemProperty -Path $key -ErrorAction SilentlyContinue
    $runtimeInputs = Get-Content (Join-Path $Root 'RUNTIME-INPUTS.json') -Raw | ConvertFrom-Json
    $requiredVc = [version]$runtimeInputs.runtimes.vc_redist.version
    if ($installed -and $installed.Installed -eq 1 -and [version]([string]$installed.Version).TrimStart('v') -ge $requiredVc) {
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
        @('ObjectName', $Service.Identity),
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
        @('AppKillProcessTree', '1'),
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
    # Validate native runtimes before provisioning or migrating any database.
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $paths.Scripts 'readiness.ps1') -Root $Root -PackageOnly -Inference -Json
    if ($LASTEXITCODE -ne 0) { throw 'Packaged runtime readiness failed before database provisioning.' }
    Set-VmsPermissions $paths

    # --- Web port
    $port = $approvedPort
    $result.web_port = $port
    $result.warnings += "Explicit public-root trust enrollment is required before opening the canonical HTTPS address."
    Write-VmsStep "Web port: $port"

    # --- php.ini and Caddyfile from the templates
    $templates = $paths.Config
    $phpIni = Expand-VmsTemplate -Text (Get-Content -LiteralPath (Join-VmsPath $templates 'php.ini.template') -Raw) -Values @{ INSTALL_DIR = $Root }
    [IO.File]::WriteAllText($paths.PhpIni, $phpIni)
    # D4 network helper validates and generates Caddy's durable-state configuration below.

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
        if (-not (Get-VmsEnvValue $envText 'APP_KEY')) { throw 'Existing APP_KEY missing; recover it explicitly.' }
        if (-not (Get-VmsEnvValue $envText 'DETECTOR_API_KEY')) { throw 'Existing integration key missing; recover it explicitly.' }
        $envText = Set-VmsEnvValue $envText 'APP_URL' 'https://philcst.vms.internal'
        $envText = Set-VmsEnvValue $envText 'DB_DATABASE' $database
        $envText = Set-VmsEnvValue $envText 'DETECTOR_PYTHON' $python
        $envText = Set-VmsEnvValue $envText 'MONITORING_SERVICES' 'managed'
        $envText = Set-VmsEnvValue $envText 'GO2RTC_WEBRTC_HOST' '127.0.0.1'
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
    # Existing installs never auto-initialize or replace setup. Interrupted
    # provisioning requires explicit local installer switches.
    if ($newDatabase -or $InitializeSetup -or $ReplaceSetupCode) {
        $setupDir = Split-Path -Parent $paths.FirstAdmin
        New-Item -ItemType Directory -Force -Path $setupDir | Out-Null
        # Restrict BEFORE PHP writes the delivery file; children inherit this ACL.
        Invoke-VmsQuiet -Exe 'icacls.exe' -Arguments @($setupDir, '/inheritance:r', '/grant:r', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F')
        $setupArguments = @('system:first-admin', "--email=$AdminEmail", "--name=$AdminName", '--initialize', "--output-file=$($paths.FirstAdmin)", '--json')
        if ($ReplaceSetupCode) { $setupArguments += '--replace' }
        $setup = (Invoke-VmsArtisan $paths $setupArguments -Quiet | Select-Object -Last 1) | ConvertFrom-Json
        $setupIssued = [bool]$setup.issued
    }
    $result.admin_email = $AdminEmail

    # Explicit D4 mode selection, default fresh installs to this computer only.
    $networkState=Join-Path $Root 'state\network.json'
    if (-not $NetworkConfig) {
        $NetworkConfig=Join-Path $Root 'config\network-selection.json'
        if (Test-Path $networkState) { $selection=(Get-Content $networkState -Raw | ConvertFrom-Json).config | ConvertTo-Json }
        else { $selection='{"schema":1,"mode":"local","address":null,"interface":null,"remote_scope":null}' }
        [IO.File]::WriteAllText($NetworkConfig,$selection)
    }
    $networkArgs=@('-B',(Join-Path $paths.App 'deploy\network\server.py'),'configure','--root',$Root,'--config',$NetworkConfig,'--prepare-only')
    if (-not (Test-Path (Join-Path $Root 'state\tls-identity.json'))) { $networkArgs+='--initialize-ca' }
    Invoke-VmsNative -Exe $paths.Python -Arguments $networkArgs | Out-Null
    Write-VmsStep 'Public files, detector settings, live view config, caches'
    Invoke-VmsArtisan $paths @('storage:link') -Quiet | Out-Null
    # Managed mode: these only write the detector's settings and go2rtc.yaml (the services run the programs).
    Invoke-VmsNative -Exe $paths.Php -Arguments @('-c', $paths.PhpIni, $paths.Artisan, 'detector:start') -OkCodes @(0, 1) -Quiet | Out-Null
    Invoke-VmsNative -Exe $paths.Php -Arguments @('-c', $paths.PhpIni, $paths.Artisan, 'go2rtc:start') -OkCodes @(0, 1) -Quiet | Out-Null
    if (-not (Test-Path -LiteralPath $paths.Go2rtcConfig)) { throw "The live view config was not written ($($paths.Go2rtcConfig))." }
    foreach ($command in 'config:cache', 'route:cache', 'view:cache') { Invoke-VmsArtisan $paths @($command) -Quiet | Out-Null }

    # --- Windows services
    Invoke-VmsNative -Exe $paths.Python -Arguments @('-B',(Join-Path $paths.App 'deploy\lifecycle\maintenance.py'),'initialize','--root',$Root) | Out-Null
    foreach ($service in Get-VmsServices $paths) {
        Write-VmsStep "Service: $($service.Display)"
        Install-VmsService $service
    }
    Start-VmsServices $paths

    # --- Task Scheduler: Laravel scheduler every minute, backup every day at 02:00
    Write-VmsStep 'Scheduled tasks'
    Register-VmsTask -Name 'PHILCST VMS Scheduler' -Schedule @('/SC', 'MINUTE', '/MO', '1') -Command (Join-VmsPath $paths.Scripts 'lib' 'scheduler.cmd')
    Register-VmsTask -Name 'PHILCST VMS Backup' -Schedule @('/SC', 'DAILY', '/ST', '02:00') -Command (Join-VmsPath $paths.Scripts 'lib' 'daily-backup.cmd')
    Disable-ScheduledTask -TaskName 'PHILCST VMS Backup' | Out-Null # D5B coherent backup engine is not implemented.

    # D2 browser access is loopback only. No network profile, hostname, power or LAN firewall changes.
    $hostname = '127.0.0.1'
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

    $result.local_url = 'https://philcst.vms.internal'
    $result.urls = @($result.local_url)
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
        [void]$lines.Add('Open after explicit installation-root trust enrollment:')
        foreach ($url in $result.urls) { [void]$lines.Add("    $url") }
        [void]$lines.Add("On this PC: $($result.local_url)")
        [void]$lines.Add('Default access is this computer only. Use the D4 network control for explicit LAN sharing; see NETWORK-SETUP.md. Trust only the public root after verifying its printed SHA-256 fingerprint.')
        if ($setupIssued) {
            [void]$lines.Add('')
            [void]$lines.Add('Complete first-admin setup at /setup with a personal password.')
            [void]$lines.Add("The setup code is in the protected local file $($paths.FirstAdmin).")
            [void]$lines.Add('An administrator must deliver it privately and delete the delivery file. It expires in 24 hours.')
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
