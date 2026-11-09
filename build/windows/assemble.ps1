# Invoked ONLY from the frozen snapshot by build-release.ps1.
param([string]$SourceRoot, [string]$Version, [string]$BuildIdentity, [string]$OutDir, [string]$CacheDir, [string]$Python,
 [switch]$SkipSelfCheck, [switch]$SkipZip, [switch]$Installer, [string]$Iscc, [string]$SigningCertificateThumbprint, [switch]$RedistributionApproved)
$ErrorActionPreference = 'Stop'; $ProgressPreference = 'SilentlyContinue'
$manifest = Get-Content (Join-Path $PSScriptRoot 'runtimes.json') -Raw | ConvertFrom-Json
$sourceInfo = Get-Content (Join-Path $SourceRoot 'SOURCE-INFO.json') -Raw | ConvertFrom-Json
$onWindows = $IsWindows -or $env:OS -eq 'Windows_NT'
if ($sourceInfo.channel -eq 'release' -and (-not $onWindows -or $SkipSelfCheck)) { throw 'Release requires native Windows checks. Use development mode for cross-assembly.' }
if ($sourceInfo.channel -eq 'development') { $Version += '-dev.' + $sourceInfo.source_sha256.Substring(0, 12) }
$stage = Join-Path $OutDir 'PHILCST-VMS'
New-Item -ItemType Directory -Force -Path $OutDir, $CacheDir | Out-Null
if (Test-Path $stage) { throw 'Output PHILCST-VMS folder already exists. Use a new isolated OutDir; no existing folder was deleted.' }
New-Item -ItemType Directory -Path $stage | Out-Null
$helper = Join-Path $PSScriptRoot 'package.py'
function Run-Python([string[]]$Arguments) { & $Python @Arguments; if ($LASTEXITCODE -ne 0) { throw 'Build helper refused the package.' } }
function Get-Pinned([string]$Name, $Entry) {
    if (-not $Entry.sha256) { throw "Immutable SHA-256 missing: $Name" }
    $file = Join-Path $CacheDir ($Name + '-' + [IO.Path]::GetFileName(([Uri]$Entry.urls[0]).AbsolutePath))
    $valid = (Test-Path $file) -and ((Get-FileHash $file -Algorithm SHA256).Hash.ToLowerInvariant() -eq $Entry.sha256)
    if (-not $valid) {
        foreach ($url in $Entry.urls) {
            $temp = $file + '.partial'
            try {
                Invoke-WebRequest -Uri $url -OutFile $temp -MaximumRedirection 10
                if ((Get-FileHash $temp -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Entry.sha256) { throw "Checksum mismatch: $Name" }
                Move-Item -LiteralPath $temp -Destination $file -Force; $valid = $true; break
            } catch { Write-Host "Input rejected for ${Name}: $($_.Exception.Message)" }
            finally { Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue }
        }
        if (-not $valid) { throw "Could not obtain a verified $Name; no partial download was accepted." }
    }
    if ($Entry.signed_by -and $onWindows) {
        $signature = Get-AuthenticodeSignature -LiteralPath $file
        if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch [regex]::Escape($Entry.signed_by)) { throw "Microsoft publisher signature failed: $Name" }
    }
    return $file
}
function Expand-Input([string]$File, [string]$Target, $Keep, [string]$Name) {
    $temp = Join-Path $CacheDir ('extract-' + [guid]::NewGuid().ToString('N'))
    try {
        Run-Python @($helper, 'extract', $File, $temp)
        $root = $temp; $children = @(Get-ChildItem $temp -Force)
        if ($children.Count -eq 1 -and $children[0].PSIsContainer) { $root = $children[0].FullName }
        New-Item -ItemType Directory -Force -Path $Target | Out-Null
        if ($Keep) { foreach ($item in $Keep) { Copy-Item (Join-Path $root $item) (Join-Path $Target ([IO.Path]::GetFileName($item))) -Force } }
        else { Copy-Item (Join-Path $root '*') $Target -Recurse -Force }
        foreach ($notice in Get-ChildItem $temp -Recurse -File | Where-Object { $_.Name -match '^(LICENSE|COPYING|NOTICE|README)' }) {
            $dest = Join-Path $stage ('licenses/' + $Name + '/' + $notice.FullName.Substring($temp.Length + 1))
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null; Copy-Item $notice.FullName $dest
        }
    } finally { Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue }
}
$env:COMPOSER_CACHE_DIR = Join-Path $CacheDir 'composer'; $env:npm_config_cache = Join-Path $CacheDir 'npm'
# Frontend is built from this snapshot; build tools never enter the target prerequisites.
& npm ci --prefix $SourceRoot --ignore-scripts --no-audit --no-fund
if ($LASTEXITCODE -ne 0) { throw 'Frozen frontend dependency installation failed.' }
Push-Location $SourceRoot
try { & npm run build; if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' } } finally { Pop-Location }
Run-Python @($helper, 'stage', $SourceRoot, $stage)
if (Test-Path (Join-Path $SourceRoot 'public/build')) { Copy-Item (Join-Path $SourceRoot 'public/build') (Join-Path $stage 'app/public/build') -Recurse -Force }
& composer install --working-dir (Join-Path $stage 'app') --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts
if ($LASTEXITCODE -ne 0) { throw 'Production Composer installation failed.' }
& composer dump-autoload --working-dir (Join-Path $stage 'app') --no-dev --optimize --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Production Composer autoload failed.' }
foreach ($property in $manifest.runtimes.PSObject.Properties) {
    if ($property.Name -eq 'go2rtc') { continue } # D4 pinned source derivative below.
    $entry = $property.Value; $file = Get-Pinned $property.Name $entry; $target = Join-Path $stage $entry.target
    if ($entry.type -eq 'zip') { Expand-Input $file $target $entry.keep $property.Name }
    else { New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null; Copy-Item $file $target }
}
Run-Python @((Join-Path $SourceRoot 'build/go2rtc/build.py'), '--cache', (Join-Path $CacheDir 'go2rtc-source'), '--out', (Join-Path $stage 'runtime/go2rtc'), '--os', 'windows', '--arch', 'amd64')
Copy-Item (Join-Path $stage 'runtime/go2rtc/GO2RTC-SOURCE-INFO.json') (Join-Path $stage 'GO2RTC-SOURCE-INFO.json')
Move-Item (Join-Path $stage 'runtime/go2rtc/GO2RTC-SOURCE-NOTICES') (Join-Path $stage 'licenses/go2rtc-source')
$manifest.runtimes.go2rtc | Add-Member -NotePropertyName packaged_derivative -NotePropertyValue (Get-Content (Join-Path $stage 'GO2RTC-SOURCE-INFO.json') -Raw | ConvertFrom-Json)
Copy-Item (Join-Path $SourceRoot 'deploy/network/NETWORK-SETUP.md') (Join-Path $stage 'NETWORK-SETUP.md')
Copy-Item (Join-Path $SourceRoot 'deploy/lifecycle/MAINTENANCE.md') (Join-Path $stage 'MAINTENANCE.md')
Copy-Item (Join-Path $SourceRoot 'deploy/clients') (Join-Path $stage 'Connect-to-Server') -Recurse
$wheelhouse = Join-Path $CacheDir ('wheelhouse-' + (Get-FileHash (Join-Path $PSScriptRoot 'wheels-win64.json')).Hash.Substring(0,12))
Run-Python @((Join-Path $PSScriptRoot 'fetch-wheels.py'), (Join-Path $PSScriptRoot 'wheels-win64.json'), $wheelhouse)
Run-Python @($helper, 'closure', $wheelhouse, (Join-Path $stage 'PYTHON-DEPENDENCIES.json'))
$site = Join-Path $stage 'runtime/python/Lib/site-packages'
& $Python -m pip install --disable-pip-version-check --no-deps --only-binary=:all: --platform win_amd64 --python-version 3.12 --implementation cp --no-index --find-links $wheelhouse --require-hashes --target $site -r (Join-Path $PSScriptRoot 'requirements-win64.lock')
if ($LASTEXITCODE -ne 0) { throw 'Hashed offline wheel installation failed.' }
# Both required distribution metadata records remain, but ONE exact cv2 payload wins.
& $Python (Join-Path $PSScriptRoot 'opencv-provider.py') $wheelhouse $site
if ($LASTEXITCODE -ne 0) { throw 'Pinned OpenCV provider audit failed.' }
Get-ChildItem $site -Recurse -Directory -Filter '__pycache__' | Remove-Item -Recurse -Force
$pth = Get-ChildItem (Join-Path $stage 'runtime/python') -Filter 'python*._pth' | Select-Object -First 1
[IO.File]::WriteAllText($pth.FullName, "python312.zip`r`n.`r`nLib\site-packages`r`n..\..\app\school-vehicle-monitoring-detector`r`nimport site`r`n")
foreach ($group in 'models', 'fixtures', 'notices') { foreach ($property in $manifest.$group.PSObject.Properties) {
    if ($property.Name -eq 'go2rtc') { continue } # D4 pinned source derivative below.
    $entry = $property.Value; $file = Get-Pinned $property.Name $entry; $target = Join-Path $stage $entry.target
    if ($group -eq 'models') { $target = Join-Path (Join-Path $stage 'app') $entry.target }
    if ($entry.type -eq 'zip') { Expand-Input $file $target $null $property.Name }
    else { New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null; Copy-Item $file $target }
} }
New-Item -ItemType Directory -Force -Path (Join-Path $stage 'config') | Out-Null
Copy-Item (Join-Path $PSScriptRoot 'templates/*') (Join-Path $stage 'config')
Copy-Item (Join-Path $PSScriptRoot 'OFFLINE-INSTALL.md') (Join-Path $stage 'OFFLINE-INSTALL.md')
Copy-Item (Join-Path $PSScriptRoot 'runtimes.json') (Join-Path $stage 'RUNTIME-INPUTS.json')
Copy-Item (Join-Path $stage 'app/composer.lock') (Join-Path $stage 'COMPOSER-DEPENDENCIES.json')
Run-Python @((Join-Path $PSScriptRoot 'license-inventory.py'), $stage, $SourceRoot)
$info = [ordered]@{ product = 'PHILCST Vehicle Monitoring'; version = $Version; build_identity = $(if ($BuildIdentity) { $BuildIdentity } else { $Version }); commit = $sourceInfo.commit; source = $sourceInfo; channel = $sourceInfo.channel; architecture = 'win_amd64'; mode = 'local-only'; built_at = (Get-Date).ToString('o'); redistribution = [bool]$RedistributionApproved; publisher_authenticated = [bool]$SigningCertificateThumbprint; tools = @{ powershell = $PSVersionTable.PSVersion.ToString(); python = (& $Python --version); php = (& php -r 'echo PHP_VERSION;'); composer = ((& composer --version --no-ansi) -join ' '); node = (& node --version) }; dependency_locks = @{ composer = (Get-FileHash (Join-Path $SourceRoot 'composer.lock') -Algorithm SHA256).Hash.ToLowerInvariant(); frontend = (Get-FileHash (Join-Path $SourceRoot 'package-lock.json') -Algorithm SHA256).Hash.ToLowerInvariant(); python = (Get-FileHash (Join-Path $PSScriptRoot 'requirements-win64.lock') -Algorithm SHA256).Hash.ToLowerInvariant(); wheel_inputs = (Get-FileHash (Join-Path $PSScriptRoot 'wheels-win64.json') -Algorithm SHA256).Hash.ToLowerInvariant() }; runtimes = $manifest.runtimes; validation = @{ snapshot = 'passed'; wheel_closure = 'passed'; cv2_payload = 'headless verified'; native = 'NOT RUN'; microsoft_signature = $(if ($onWindows) { 'verified' } else { 'NOT RUN' }); cpu_inference = 'NOT RUN' }; optional_capabilities = @{ tesseract = 'unavailable'; standalone_ffmpeg = 'unavailable'; gpu = 'unsupported candidate'; lan_https = 'D4 pending' }; release_gates = @('Native clean offline acceptance', 'D4 HTTPS/client setup', 'D5 recovery/outbox', 'Redistribution/signing/source approval') }
$buildFile = Join-Path $stage 'BUILD-INFO.json'; $info | ConvertTo-Json -Depth 10 | Set-Content $buildFile -Encoding utf8NoBOM
if ($onWindows -and -not $SkipSelfCheck) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $stage 'app/deploy/windows/readiness.ps1') -Root $stage -PackageOnly -Inference -Json
    if ($LASTEXITCODE -ne 0) { throw 'Packaged native PHP/CPU inference failed.' }
    $info.validation.native = 'runtime checks passed, acceptance still required'; $info.validation.cpu_inference = 'recorded fixture passed'
    & (Join-Path $stage 'runtime/caddy/caddy.exe') version; if ($LASTEXITCODE -ne 0) { throw 'Caddy runtime failed' }
    & (Join-Path $stage 'runtime/go2rtc/go2rtc.exe') -version; if ($LASTEXITCODE -ne 0) { throw 'go2rtc runtime failed' }
    $info | ConvertTo-Json -Depth 10 | Set-Content $buildFile -Encoding utf8NoBOM
}
Run-Python @($helper, 'manifest', $stage)
if ($SigningCertificateThumbprint) {
    if (-not $onWindows) { throw 'Publisher signing requires the Windows certificate store; private keys must never enter the package.' }
    $cert = Get-Item ('Cert:\CurrentUser\My\' + $SigningCertificateThumbprint)
    $rsa = [Security.Cryptography.X509Certificates.RSACertificateExtensions]::GetRSAPrivateKey($cert)
    try { $signature = $rsa.SignData([IO.File]::ReadAllBytes((Join-Path $stage 'CONTENT-MANIFEST.json')), [Security.Cryptography.HashAlgorithmName]::SHA256, [Security.Cryptography.RSASignaturePadding]::Pkcs1) } finally { $rsa.Dispose() }
    @{ certificate = [Convert]::ToBase64String($cert.RawData); signature = [Convert]::ToBase64String($signature); algorithm = 'RSA-SHA256'; thumbprint = $cert.Thumbprint } | ConvertTo-Json | Set-Content (Join-Path $stage 'CONTENT-SIGNATURE.json') -Encoding utf8NoBOM
}
Run-Python @($helper, 'verify', $stage)
if (-not $SkipZip) {
    $zip = Join-Path $OutDir "PHILCST-VMS-$Version-win64.zip"
    [IO.Compression.ZipFile]::CreateFromDirectory($stage, $zip, [IO.Compression.CompressionLevel]::Optimal, $true)
    ((Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + [IO.Path]::GetFileName($zip)) | Set-Content "$zip.sha256" -Encoding ascii
    # Validate extracted bytes, not merely the staging directory.
    $extracted = Join-Path $OutDir 'verified-extraction'; Run-Python @($helper, 'extract', $zip, $extracted)
    Run-Python @($helper, 'verify', (Join-Path $extracted 'PHILCST-VMS'))
    Remove-Item $extracted -Recurse -Force
}
if ($Installer) {
    if (-not $onWindows -or $SkipZip) { throw 'Installer requires Windows/Inno and the verified complete ZIP.' }
    if (-not $Iscc) { $Iscc = (Get-Command ISCC.exe -ErrorAction Stop).Source }
    & $Iscc /Qp "/DAppVersion=$Version" "/DPayloadZip=$zip" "/DCandidate=$($sourceInfo.channel -eq 'development')" "/O$OutDir" (Join-Path $PSScriptRoot 'installer/PHILCST-VMS.iss')
    if ($LASTEXITCODE -ne 0) { throw 'Inno Setup compilation failed' }
    $setup = Join-Path $OutDir "PHILCST-VMS-Setup-$Version.exe"
    if ($SigningCertificateThumbprint) { $signed = Set-AuthenticodeSignature -FilePath $setup -Certificate $cert -HashAlgorithm SHA256; if ($signed.Status -ne 'Valid') { throw 'Installer publisher signing failed' } }
    ((Get-FileHash $setup -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + [IO.Path]::GetFileName($setup)) | Set-Content "$setup.sha256" -Encoding ascii
}
if (-not $SkipZip) {
    Run-Python @((Join-Path $SourceRoot 'build/updates/generate.py'), '--stage', $stage, '--package', $zip, '--output', ($zip + '.update.json'))
}
Write-Host "Candidate prepared: $stage. Native acceptance, source approval and release authorization are separate."
