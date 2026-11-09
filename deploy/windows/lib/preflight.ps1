# Target-side checks. PowerShell 5.1; pure policy functions are testable on macOS.
function Assert-VmsPlatform {
    param([version]$Version, [string]$Architecture, [bool]$Elevated, [bool]$LocalDrive, [long]$FreeBytes, [long]$RequiredBytes)
    if ($Version -lt [version]'10.0') { throw 'Windows 10 or newer is required.' }
    if ($Architecture -ne 'AMD64') { throw 'Server requires native Intel/AMD x64 (AMD64). ARM64/emulation and x86 are unsupported; browser clients are separate.' }
    if (-not $Elevated) { throw 'Run the installer as administrator.' }
    if (-not $LocalDrive) { throw 'Install and SQLite database must be on a local fixed disk, not UNC, mapped network or removable storage.' }
    if ($FreeBytes -lt $RequiredBytes) { throw 'Insufficient disk space for the complete package and installation safety margin.' }
}

function Test-VmsOwnedCommand {
    param([string]$Command, [string]$Executable)
    if (-not $Command -or -not $Executable) { return $false }
    $pattern = '^\s*(?:"' + [regex]::Escape($Executable) + '"|' + [regex]::Escape($Executable) + ')(?:\s|$)'
    return [regex]::IsMatch($Command, $pattern, [Text.RegularExpressions.RegexOptions]::IgnoreCase)
}

function Test-VmsServiceOwned {
    param($Paths, [string]$Name)
    $service = Get-CimInstance Win32_Service -Filter "Name='$Name'" -ErrorAction SilentlyContinue
    if (-not $service) { return $false }
    if (-not (Test-VmsOwnedCommand $service.PathName $Paths.Nssm)) { return $false }
    $parameters = Get-ItemProperty -LiteralPath "HKLM:\SYSTEM\CurrentControlSet\Services\$Name\Parameters" -ErrorAction SilentlyContinue
    return ($parameters -and $parameters.Application -and $parameters.Application.StartsWith($Paths.Runtime.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase))
}

function Test-VmsPayload {
    param([Parameter(Mandatory = $true)][string]$Payload, [switch]$Installed, [switch]$AllowDevelopmentCandidate, [string]$ExpectedPublisherThumbprint = '')
    $manifestFile = Join-Path $Payload 'CONTENT-MANIFEST.json'
    $buildFile = Join-Path $Payload 'BUILD-INFO.json'
    if (-not (Test-Path -LiteralPath $manifestFile) -or -not (Test-Path -LiteralPath $buildFile)) { throw 'Missing content manifest or BUILD-INFO. Obtain a complete package.' }
    $build = Get-Content -LiteralPath $buildFile -Raw | ConvertFrom-Json
    if ($build.architecture -ne 'win_amd64' -or $build.mode -ne 'local-only') { throw 'Wrong package architecture or deployment mode.' }
    if ($build.channel -ne 'release' -and -not $AllowDevelopmentCandidate) { throw 'NON-RELEASE development candidate. Explicit -AllowDevelopmentCandidate is required; native acceptance and release approval remain separate.' }
    if ($build.channel -eq 'release' -and -not $Installed) {
        if (-not $ExpectedPublisherThumbprint) { throw 'Release installation requires the independently verified publisher certificate thumbprint.' }
        $signed = Get-Content -LiteralPath (Join-Path $Payload 'CONTENT-SIGNATURE.json') -Raw | ConvertFrom-Json
        $cert = New-Object Security.Cryptography.X509Certificates.X509Certificate2 (,[Convert]::FromBase64String($signed.certificate))
        if ($cert.Thumbprint -ne $ExpectedPublisherThumbprint.Replace(' ', '')) { throw 'Publisher fingerprint does not match the independently approved value.' }
        $rsa = [Security.Cryptography.X509Certificates.RSACertificateExtensions]::GetRSAPublicKey($cert)
        try {
            $valid = $rsa.VerifyData([IO.File]::ReadAllBytes($manifestFile), [Convert]::FromBase64String($signed.signature), [Security.Cryptography.HashAlgorithmName]::SHA256, [Security.Cryptography.RSASignaturePadding]::Pkcs1)
            if (-not $valid) { throw 'Invalid manifest publisher signature.' }
        } finally { $rsa.Dispose() }
    }
    $manifest = Get-Content -LiteralPath $manifestFile -Raw | ConvertFrom-Json
    if ($manifest.format -ne 1) { throw 'Unsupported content manifest format.' }
    $expected = @{}
    foreach ($file in $manifest.files.PSObject.Properties) {
        $name = $file.Name
        if ($name -match '(^[/\\]|\.\.|:)' -or $name -match '[<>"|?*]' ) { throw 'Unsafe package path in manifest.' }
        $path = Join-Path $Payload ($name.Replace('/', [IO.Path]::DirectorySeparatorChar))
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing dependency: $name. Re-extract the complete verified package; no download will be attempted." }
        if ((Get-Item -Force -LiteralPath $path).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Package contains a reparse point.' }
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $file.Value.sha256) { throw "Corrupt dependency: $name. Re-extract the complete verified package; no download will be attempted." }
        $expected[$name.ToLowerInvariant()] = $true
    }
    if (-not $Installed) {
        foreach ($f in Get-ChildItem -LiteralPath $Payload -Recurse -File -Force) {
            $name = $f.FullName.Substring($Payload.TrimEnd('\', '/').Length + 1).Replace('\', '/')
            if ($name -notin @('CONTENT-MANIFEST.json', 'CONTENT-SIGNATURE.json') -and -not $expected.ContainsKey($name.ToLowerInvariant())) { throw "Unexpected file in package: $name" }
        }
    }
    return $manifest
}

function Assert-VmsInstallPreflight {
    param([string]$Root, [string]$Payload, [switch]$AllowDevelopmentCandidate, [string]$ExpectedPublisherThumbprint = '', [switch]$Installed)
    if ($env:OS -ne 'Windows_NT') { throw 'Native Windows execution required. PowerShell parsing on another OS is not installation acceptance.' }
    if (-not (Test-VmsInstallFolder $Root) -or $Root.TrimEnd('\').Length -le 3) { throw 'Use an absolute local install folder without spaces or special letters, for example C:\PHILCST-VMS.' }
    $manifest = Test-VmsPayload -Payload $Payload -Installed:$Installed -AllowDevelopmentCandidate:$AllowDevelopmentCandidate -ExpectedPublisherThumbprint $ExpectedPublisherThumbprint
    foreach ($entry in $manifest.files.PSObject.Properties) { if ($Root.Length + 1 + $entry.Name.Length -gt 259) { throw 'Unsupported path length. Choose a shorter root; no partial installation was attempted.' } }
    $vc = Get-AuthenticodeSignature -LiteralPath (Join-Path $Payload 'runtime\vc_redist.x64.exe')
    if ($vc.Status -ne 'Valid' -or -not $vc.SignerCertificate -or $vc.SignerCertificate.Subject -notmatch 'Microsoft Corporation') { throw 'Bundled VC++ Microsoft publisher signature could not be verified offline. Do not execute or download a replacement automatically.' }
    $native = (Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Environment').PROCESSOR_ARCHITECTURE
    $drive = Get-CimInstance Win32_LogicalDisk -Filter ("DeviceID='" + $Root.Substring(0, 2) + "'")
    Assert-VmsPlatform -Version ([Environment]::OSVersion.Version) -Architecture $native -Elevated (Test-VmsAdmin) -LocalDrive ([bool]($drive -and $drive.DriveType -eq 3)) -FreeBytes ([long]$drive.FreeSpace) -RequiredBytes ([long]$manifest.total_bytes * 2 + 2GB)
    $parent = $Root
    while ($parent -and $parent.Length -gt 3) {
        if ((Test-Path -LiteralPath $parent) -and ((Get-Item -Force -LiteralPath $parent).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Install root/parents may not be junctions or symlinks.' }
        $parent = Split-Path -Parent $parent
    }
    $paths = Get-VmsPaths $Root
    if (-not $Installed -and [IO.Path]::GetFullPath($Root).TrimEnd('\') -eq [IO.Path]::GetFullPath($Payload).TrimEnd('\')) { throw 'Extract into a separate staging folder; Payload and installation Root must differ.' }
    if ((Test-Path $Root) -and @(Get-ChildItem -LiteralPath $Root -Force).Count -gt 0 -and -not (Test-Path $paths.BuildInfo)) { throw 'Nonempty unrecognized installation root. Choose an empty root or use the separately approved legacy recovery workflow; nothing was overwritten.' }
    # Inspect existing destination parent directories before any verified program file is copied.
    $checked = @{}
    foreach ($entry in $manifest.files.PSObject.Properties) {
        $candidate = Join-Path $Root $entry.Name.Replace('/', '\')
        while ($candidate -and $candidate.Length -gt $Root.Length -and -not $checked.ContainsKey($candidate)) {
            $checked[$candidate] = $true
            if ((Test-Path -LiteralPath $candidate) -and ((Get-Item -Force -LiteralPath $candidate).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Existing program path redirects outside the installation. No files were copied.' }
            $candidate = Split-Path -Parent $candidate
        }
    }
    foreach ($location in $paths.App, (Split-Path -Parent $paths.Database), $paths.Database, $paths.Storage) {
        if ((Test-Path -LiteralPath $location) -and ((Get-Item -Force -LiteralPath $location).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Application/database/state paths may not redirect to another volume or network share.' }
    }
    if ((Test-Path $paths.Env) -and -not (Test-Path $paths.Database)) { throw 'Existing .env without its SQLite database. Recover the installation explicitly; no empty replacement database or new setup state was created.' }
    if ((Test-Path $paths.Database) -and -not (Test-Path $paths.Env)) { throw 'Existing database without .env encryption keys: stop and recover the installation; no new secrets will be generated.' }
    if ((Test-Path $paths.Env) -and -not (Test-Path (Join-Path $Root 'CONTENT-MANIFEST.json'))) { throw 'Legacy installation detected. D2 does not upgrade existing deployments; use the separately approved D5 workflow. Data was not changed.' }
    if ((Test-Path $paths.BuildInfo) -and -not $Installed) {
        $old = Get-Content $paths.BuildInfo -Raw | ConvertFrom-Json
        $new = Get-Content (Join-Path $Payload 'BUILD-INFO.json') -Raw | ConvertFrom-Json
        if ($old.source.source_sha256 -ne $new.source.source_sha256) { throw 'Different source version already installed. Use D5 update, not reinstall; existing data was preserved.' }
    }
    if (Test-Path $paths.Env) {
        $text = Get-Content -LiteralPath $paths.Env -Raw
        if ((Get-VmsEnvValue $text 'DB_CONNECTION') -ne 'sqlite') { throw 'Existing non-SQLite installation. A separate conversion workflow is required; nothing was converted.' }
        $db = (Get-VmsEnvValue $text 'DB_DATABASE').Trim('"', "'").Replace('/', '\')
        if ($db -and $db -ne $paths.Database) { throw 'Existing DB_DATABASE points outside the expected local database. No path was overwritten.' }
        foreach ($key in 'APP_KEY', 'DETECTOR_API_KEY') { if (-not (Get-VmsEnvValue $text $key)) { throw "Existing installation is missing $key; recover it explicitly instead of generating a replacement." } }
    }
    foreach ($service in Get-VmsServices $paths) {
        if ((Get-Service -Name $service.Name -ErrorAction SilentlyContinue) -and -not (Test-VmsServiceOwned $paths $service.Name)) { throw "Service name belongs to another installation: $($service.Name). Nothing was stopped." }
    }
    if (Get-ScheduledTask -TaskName 'PHILCST Vehicle Monitoring' -ErrorAction SilentlyContinue) { throw 'Conflicting development autostart task. Stop/remove that development startup explicitly before installing.' }
    foreach ($taskName in $script:VmsTaskNames) {
        $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
        if ($task -and (($task.Actions | Out-String) -notmatch [regex]::Escape($paths.Scripts))) { throw "Scheduled task is owned by another installation: $taskName" }
    }
    $owned = @{}
    foreach ($process in Get-CimInstance Win32_Process) {
        if ($process.ExecutablePath -and $process.ExecutablePath.StartsWith($paths.Runtime + '\', [StringComparison]::OrdinalIgnoreCase)) { $owned[[int]$process.ProcessId] = $true }
        elseif ($process.CommandLine -match '(camera_service|device_service)\.py') { throw "Another collector is running (PID $($process.ProcessId)); stop its owning installation explicitly." }
    }
    foreach ($port in 443, 8080, 1984, 8555, 8765, 9001, 9002, 9003, 9004) {
        foreach ($listener in @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)) {
            if (-not $owned.ContainsKey([int]$listener.OwningProcess)) { throw "Required port $port belongs to another program (PID $($listener.OwningProcess)); no process was killed." }
        }
    }
    foreach ($listener in @(Get-NetUDPEndpoint -LocalPort 8555 -ErrorAction SilentlyContinue)) {
        if (-not $owned.ContainsKey([int]$listener.OwningProcess)) { throw 'Required UDP port 8555 belongs to another program; no process was killed.' }
    }
    # D4 canonical TLS is fixed at 443; protected collector endpoint remains 8080.
    return 8080
}

function Set-VmsPermissions {
    param($Paths)
    # Administrators/SYSTEM own code. LocalService can read code and write only runtime state.
    Invoke-VmsNative -Exe 'icacls.exe' -Arguments @($Paths.Root, '/inheritance:r', '/grant:r', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F', '*S-1-5-19:(OI)(CI)RX') -Quiet | Out-Null
    foreach ($directory in $Paths.Storage, (Split-Path -Parent $Paths.Database), (Join-VmsPath $Paths.App 'bootstrap' 'cache'), $Paths.Logs) {
        New-Item -ItemType Directory -Force -Path $directory | Out-Null
        Invoke-VmsNative -Exe 'icacls.exe' -Arguments @($directory, '/grant:r', '*S-1-5-19:(OI)(CI)M') -Quiet | Out-Null
    }
    foreach ($file in $Paths.Env, $Paths.Go2rtcConfig) {
        if (Test-Path $file) { Invoke-VmsNative -Exe 'icacls.exe' -Arguments @($file, '/inheritance:r', '/grant:r', '*S-1-5-32-544:F', '*S-1-5-18:F', $(if ($file -eq $Paths.Env) { '*S-1-5-19:R' } else { '*S-1-5-19:M' })) -Quiet | Out-Null }
    }
}
