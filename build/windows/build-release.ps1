<#
PHILCST Vehicle Monitoring - builds the self-contained Windows kit.

    pwsh build/windows/build-release.ps1 [-OutDir build/windows/dist] [-CacheDir build/windows/cache]

Output: <OutDir>/PHILCST-VMS-<version>-win64.zip (+ .sha256), a folder with
everything the PC needs, offline:

    PHILCST-VMS/
      app/                  the Laravel app (git HEAD) + vendor (composer --no-dev)
        school-vehicle-monitoring-detector/models/   YOLO + EasyOCR models
      runtime/php/          PHP 8.4 NTS x64 (php-cgi for FastCGI)
      runtime/python/       embeddable Python 3.12 + every package (requirements-windows.txt)
      runtime/caddy/        web server
      runtime/go2rtc/       live view (WebRTC)
      runtime/nssm/         Windows services
      runtime/vc_redist.x64.exe
      config/               php.ini / Caddyfile templates (the installer fills them in)
      BUILD-INFO.json       versions and hashes

Every download is checked against build/windows/runtimes.json (SHA-256, or
the Microsoft signature for the VC++ runtime). Downloads are cached in
<CacheDir>. Needs: git, php + composer (on PATH), python 3 with pip.
Runs on Windows (CI: windows-latest) and, for checking, on macOS/Linux with
PowerShell 7 (the Windows packages are installed with pip --platform).
#>
param(
    [string]$OutDir = (Join-Path $PSScriptRoot 'dist'),
    [string]$CacheDir = (Join-Path $PSScriptRoot 'cache'),
    [string]$Python = '',
    [switch]$SkipSelfCheck,
    [switch]$SkipZip
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest is many times faster without it
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..' '..')).Path
$Manifest = Get-Content (Join-Path $PSScriptRoot 'runtimes.json') -Raw | ConvertFrom-Json
$OnWindows = $IsWindows -or ($env:OS -eq 'Windows_NT')

function Step([string]$Text) { Write-Host "==> $Text" -ForegroundColor Cyan }

function Get-Sha256([string]$Path) { (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant() }

# One pinned file from the manifest, downloaded once into the cache and checked.
function Get-Pinned([string]$Name, $Entry) {
    New-Item -ItemType Directory -Force -Path $CacheDir | Out-Null
    $file = Join-Path $CacheDir ($Name + '-' + [IO.Path]::GetFileName(([Uri]$Entry.urls[0]).AbsolutePath))
    if ((Test-Path $file) -and $Entry.sha256 -and (Get-Sha256 $file) -eq $Entry.sha256) { return $file }

    foreach ($url in $Entry.urls) {
        try {
            Write-Host "    downloading $url"
            Invoke-WebRequest -Uri $url -OutFile $file -UserAgent 'PHILCST-VMS-build' -MaximumRedirection 10
            break
        } catch {
            Write-Host "    failed: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
    if (-not (Test-Path $file)) { throw "Could not download $Name." }

    if ($Entry.sha256) {
        $actual = Get-Sha256 $file
        if ($actual -ne $Entry.sha256) {
            Remove-Item $file -Force
            throw "$Name has SHA-256 $actual, expected $($Entry.sha256). Not used."
        }
    } elseif ($Entry.signed_by) {
        if ($OnWindows) {
            $signature = Get-AuthenticodeSignature -FilePath $file
            if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch [regex]::Escape($Entry.signed_by)) {
                throw "$Name is not signed by $($Entry.signed_by) (status $($signature.Status))."
            }
        } else {
            Write-Host "    ${Name}: the signature can only be checked on Windows (the CI build does)." -ForegroundColor Yellow
        }
    } else {
        throw "$Name has neither a SHA-256 nor a signer in runtimes.json."
    }
    return $file
}

# Unpack a zip into $Target; one top folder in the zip is dropped. $Keep: only these files.
function Expand-Pinned([string]$Zip, [string]$Target, $Keep) {
    $temp = Join-Path ([IO.Path]::GetTempPath()) ('philcst-' + [guid]::NewGuid())
    Expand-Archive -LiteralPath $Zip -DestinationPath $temp -Force
    $root = $temp
    $entries = @(Get-ChildItem -LiteralPath $temp -Force)
    if ($entries.Count -eq 1 -and $entries[0].PSIsContainer) { $root = $entries[0].FullName }
    New-Item -ItemType Directory -Force -Path $Target | Out-Null
    if ($Keep) {
        foreach ($item in $Keep) {
            Copy-Item -LiteralPath (Join-Path $root $item) -Destination (Join-Path $Target ([IO.Path]::GetFileName($item))) -Force
        }
    } else {
        Copy-Item -Path (Join-Path $root '*') -Destination $Target -Recurse -Force
    }
    Remove-Item -LiteralPath $temp -Recurse -Force
}

function Write-Template([string]$Template, [string]$Destination, [hashtable]$Values) {
    $text = Get-Content -LiteralPath $Template -Raw
    foreach ($key in $Values.Keys) { $text = $text.Replace('{{' + $key + '}}', $Values[$key]) }
    # Windows tools read these files; CRLF keeps Notepad and cmd happy.
    [IO.File]::WriteAllText($Destination, ($text -replace "`r?`n", "`r`n"))
}

$commit = (git -C $Repo rev-parse --short HEAD).Trim()
$Version = (Get-Date -Format 'yyyy.MM.dd') + '-' + $commit
$Stage = Join-Path $OutDir 'PHILCST-VMS'
Step "Building PHILCST-VMS $Version"
if (Test-Path $Stage) { Remove-Item -LiteralPath $Stage -Recurse -Force }
New-Item -ItemType Directory -Force -Path $Stage, (Join-Path $Stage 'runtime'), (Join-Path $Stage 'config') | Out-Null

# --- 1. The app (committed files only; .gitattributes export-ignore drops tests and build files)
Step 'App files (git HEAD)'
$archive = Join-Path $CacheDir "app-$commit.zip"
New-Item -ItemType Directory -Force -Path $CacheDir | Out-Null
git -C $Repo archive --format=zip -o $archive HEAD
Expand-Archive -LiteralPath $archive -DestinationPath (Join-Path $Stage 'app') -Force

Step 'Composer packages (no dev packages)'
$env:COMPOSER_CACHE_DIR = Join-Path $CacheDir 'composer'
composer install --working-dir (Join-Path $Stage 'app') --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts
if ($LASTEXITCODE -ne 0) { throw 'composer install failed.' }
composer dump-autoload --working-dir (Join-Path $Stage 'app') --no-dev --optimize --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'composer dump-autoload failed.' }

# --- 2. Runtimes
foreach ($property in $Manifest.runtimes.PSObject.Properties) {
    $name = $property.Name; $entry = $property.Value
    Step "Runtime: $name $($entry.version)"
    $file = Get-Pinned $name $entry
    $target = Join-Path $Stage $entry.target
    if ($entry.type -eq 'zip') {
        Expand-Pinned $file $target $entry.keep
    } else {
        Copy-Item -LiteralPath $file -Destination $target -Force
    }
}

# --- 3. Python packages (Windows wheels, exact versions) and the embeddable path file
Step 'Python packages for Windows (pip --platform win_amd64, Python 3.12)'
if (-not $Python) { $Python = if ($OnWindows) { 'python' } else { 'python3' } }
$sitePackages = Join-Path $Stage 'runtime/python/Lib/site-packages'
& $Python -m pip install --disable-pip-version-check --no-deps --only-binary=:all: `
    --platform win_amd64 --python-version 3.12 --implementation cp `
    --cache-dir (Join-Path $CacheDir 'pip') --target $sitePackages `
    -r (Join-Path $Repo 'school-vehicle-monitoring-detector/requirements-windows.txt')
if ($LASTEXITCODE -ne 0) { throw 'pip install failed.' }
Get-ChildItem -LiteralPath $sitePackages -Recurse -Directory -Filter '__pycache__' | Remove-Item -Recurse -Force
# The embeddable Python reads only the paths in python312._pth (isolated): the
# packages, and the detector folder so it can import its own modules.
$pth = Get-ChildItem -LiteralPath (Join-Path $Stage 'runtime/python') -Filter 'python*._pth' | Select-Object -First 1
$zipName = (Get-ChildItem -LiteralPath (Join-Path $Stage 'runtime/python') -Filter 'python*.zip' | Select-Object -First 1).Name
[IO.File]::WriteAllText($pth.FullName, (@($zipName, '.', 'Lib\site-packages', '..\..\app\school-vehicle-monitoring-detector', 'import site') -join "`r`n") + "`r`n")

# --- 4. Models (offline: the detector never downloads)
foreach ($property in $Manifest.models.PSObject.Properties) {
    Step "Model: $($property.Name)"
    $file = Get-Pinned $property.Name $property.Value
    $target = Join-Path (Join-Path $Stage 'app') $property.Value.target
    if ($property.Value.type -eq 'zip') {
        Expand-Pinned $file $target $null
    } else {
        New-Item -ItemType Directory -Force -Path (Split-Path $target) | Out-Null
        Copy-Item -LiteralPath $file -Destination $target -Force
    }
}

# --- 5. Config templates (the installer fills in the folder and port) + a php.ini for the self-check
Step 'Config templates'
Copy-Item (Join-Path $PSScriptRoot 'templates/*') (Join-Path $Stage 'config') -Force
Write-Template (Join-Path $PSScriptRoot 'templates/php.ini.template') (Join-Path $Stage 'runtime/php/php.ini') @{ INSTALL_DIR = $Stage }

$info = [ordered]@{
    product  = 'PHILCST Vehicle Monitoring'
    version  = $Version
    commit   = (git -C $Repo rev-parse HEAD).Trim()
    built_at = (Get-Date).ToString('o')
    runtimes = [ordered]@{}
    models   = [ordered]@{}
}
foreach ($property in $Manifest.runtimes.PSObject.Properties) { $info.runtimes[$property.Name] = $property.Value.version }
foreach ($property in $Manifest.models.PSObject.Properties) { $info.models[$property.Name] = $property.Value.sha256 }
$info | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $Stage 'BUILD-INFO.json') -Encoding utf8

# --- 6. Self-check (Windows only: the programs are Windows programs)
if ($OnWindows -and -not $SkipSelfCheck) {
    Step 'Self-check'
    $php = Join-Path $Stage 'runtime/php/php.exe'
    $modules = (& $php -c (Join-Path $Stage 'runtime/php/php.ini') -m) -join ' '
    foreach ($module in 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip', 'Zend OPcache') {
        if ($modules -notmatch [regex]::Escape($module)) { throw "PHP is missing the $module extension." }
    }
    Write-Host '    PHP extensions: ok'
    & $php -c (Join-Path $Stage 'runtime/php/php.ini') (Join-Path $Stage 'app/artisan') --version
    if ($LASTEXITCODE -ne 0) { throw 'php artisan does not run.' }

    $pythonExe = Join-Path $Stage 'runtime/python/python.exe'
    & $pythonExe -c "import cv2, numpy, torch, ultralytics, easyocr, psutil, requests, config; print('    Python', __import__('sys').version.split()[0], 'cv2', cv2.__version__, 'torch', torch.__version__, 'models', config.MODEL_PATH)"
    if ($LASTEXITCODE -ne 0) { throw 'The bundled Python cannot import the detector packages.' }
    & (Join-Path $Stage 'runtime/caddy/caddy.exe') version
    & (Join-Path $Stage 'runtime/go2rtc/go2rtc.exe') -version
    if (-not (Test-Path (Join-Path $Stage 'runtime/nssm/nssm.exe'))) { throw 'nssm.exe is missing.' }
}

# --- 7. The release ZIP
if (-not $SkipZip) {
    Step 'Release ZIP'
    $zip = Join-Path $OutDir "PHILCST-VMS-$Version-win64.zip"
    if (Test-Path $zip) { Remove-Item $zip -Force }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::CreateFromDirectory($Stage, $zip, [IO.Compression.CompressionLevel]::Optimal, $true)
    $hash = Get-Sha256 $zip
    Set-Content -LiteralPath "$zip.sha256" -Value "$hash  $([IO.Path]::GetFileName($zip))" -Encoding ascii
    $size = [math]::Round((Get-Item $zip).Length / 1MB)
    Write-Host "    $zip ($size MB)"
    Write-Host "    SHA-256 $hash"
}

Step 'Done'
