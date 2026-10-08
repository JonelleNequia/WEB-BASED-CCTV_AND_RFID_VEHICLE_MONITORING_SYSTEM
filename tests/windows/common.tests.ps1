<#
Tests of deploy/windows/lib/common.ps1 (the parts that do not need Windows).
    pwsh -NoProfile -File tests/windows/common.tests.ps1
Exit code 0 = all passed. Also run by tests/Feature/WindowsScriptsTest.php.
#>
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
. (Join-Path $repo 'deploy/windows/lib/common.ps1')

$script:failures = 0
$script:count = 0
function Test-That {
    param([string]$Name, [scriptblock]$Body)
    $script:count++
    try {
        & $Body
        Write-Host "ok   $Name"
    } catch {
        $script:failures++
        Write-Host "FAIL $Name -- $($_.Exception.Message)"
    }
}
function Assert-Equal($Expected, $Actual, [string]$What = '') {
    if ($Expected -ne $Actual) { throw "$What expected [$Expected], got [$Actual]" }
}
function Assert-True($Condition, [string]$What = '') { if (-not $Condition) { throw "not true: $What" } }
function Assert-Throws([scriptblock]$Body, [string]$Pattern) {
    try { & $Body } catch { if ($_.Exception.Message -notmatch $Pattern) { throw "threw [$($_.Exception.Message)], expected /$Pattern/" }; return }
    throw "did not throw /$Pattern/"
}
function New-TempDir { $d = Join-Path ([IO.Path]::GetTempPath()) ('vms-test-' + [guid]::NewGuid().ToString('N')); New-Item -ItemType Directory -Path $d | Out-Null; return $d }

Test-That 'web port: 80 when free, else 8080, else an error' {
    Assert-Equal 80 (Select-VmsWebPort -IsFree { param($p) $true })
    Assert-Equal 8080 (Select-VmsWebPort -IsFree { param($p) $p -ne 80 })
    Assert-Equal 8090 (Select-VmsWebPort -IsFree { param($p) $p -eq 8090 })
    Assert-Throws { Select-VmsWebPort -IsFree { param($p) $false } } 'None of the web ports'
}

Test-That 'APP_KEY is base64 of 32 random bytes; the API key 48 hex characters' {
    $key = New-VmsAppKey
    Assert-True ($key -match '^base64:[A-Za-z0-9+/]{43}=$') "format of $key"
    Assert-Equal 32 ([Convert]::FromBase64String($key.Substring(7)).Length) 'bytes'
    Assert-True ($key -ne (New-VmsAppKey)) 'a new key each time'
    Assert-True ((New-VmsApiKey) -match '^[0-9a-f]{48}$') 'api key'
}

Test-That 'the .env template becomes a production .env with every value filled in' {
    $template = Get-Content -LiteralPath (Join-Path $repo 'build/windows/templates/env.template') -Raw
    $text = Expand-VmsTemplate -Text $template -Values @{ APP_KEY = 'base64:abc'; API_KEY = 'f00d'; WEB_PORT = 8080; DATABASE = 'C:/PHILCST-VMS/app/database/database.sqlite'; PYTHON = 'C:/PHILCST-VMS/runtime/python/python.exe' }
    foreach ($line in 'APP_ENV=production', 'APP_DEBUG=false', 'APP_TIMEZONE=Asia/Manila', 'APP_KEY=base64:abc', 'DETECTOR_API_KEY=f00d',
        'APP_URL=http://127.0.0.1:8080', 'MONITORING_SERVICES=managed', 'DETECTOR_STREAM_PROXY=true', 'QUEUE_CONNECTION=sync', 'DB_DATABASE=C:/PHILCST-VMS/app/database/database.sqlite') {
        Assert-True ($text -match ('(?m)^' + [regex]::Escape($line) + '\r?$')) $line
    }
    Assert-True ($text.Contains("`r`n") -and -not ($text -match "[^`r]`n")) 'CRLF line ends'
    Assert-Throws { Expand-VmsTemplate -Text $template -Values @{ APP_KEY = 'x' } } 'Template value missing'
}

Test-That 'Caddyfile and php.ini templates fill in the folder and port' {
    $caddy = Expand-VmsTemplate -Text (Get-Content -LiteralPath (Join-Path $repo 'build/windows/templates/Caddyfile.template') -Raw) -Values @{ INSTALL_DIR = 'C:/PHILCST-VMS'; WEB_PORT = 8080 }
    Assert-True ($caddy.Contains(':8080 {')) 'site port'
    Assert-True ($caddy.Contains('root * "C:/PHILCST-VMS/app/public"')) 'root'
    Assert-True ($caddy.Contains('forward_auth 127.0.0.1:8080')) 'forward_auth to itself'
    Assert-True ($caddy.Contains('reverse_proxy 127.0.0.1:8765')) 'detector proxy'
    $ini = Expand-VmsTemplate -Text (Get-Content -LiteralPath (Join-Path $repo 'build/windows/templates/php.ini.template') -Raw) -Values @{ INSTALL_DIR = 'C:\PHILCST-VMS' }
    Assert-True ($ini.Contains('extension_dir = "C:\PHILCST-VMS\runtime\php\ext"')) 'extension_dir'
}

Test-That '.env values are replaced in place or added, literally' {
    $text = "APP_NAME=x`r`nAPP_URL=http://127.0.0.1:80`r`nAPP_KEY=old`r`n"
    $text = Set-VmsEnvValue $text 'APP_URL' 'http://127.0.0.1:8080'
    $text = Set-VmsEnvValue $text 'APP_KEY' 'base64:a$1b+/='
    $text = Set-VmsEnvValue $text 'NEW_ONE' 'yes'
    Assert-Equal 'http://127.0.0.1:8080' (Get-VmsEnvValue $text 'APP_URL')
    Assert-Equal 'base64:a$1b+/=' (Get-VmsEnvValue $text 'APP_KEY')
    Assert-Equal 'yes' (Get-VmsEnvValue $text 'NEW_ONE')
    Assert-Equal 1 ([regex]::Matches($text, '(?m)^APP_URL=').Count) 'one APP_URL'
    Assert-Equal $null (Get-VmsEnvValue $text 'MISSING')
    Assert-Equal 'v' (Get-VmsEnvValue (Set-VmsEnvValue '' 'K' 'v') 'K') 'empty file'
}

Test-That 'install folder: no spaces or special letters' {
    Assert-True (Test-VmsInstallFolder 'C:\PHILCST-VMS') 'default'
    Assert-True (Test-VmsInstallFolder 'D:\Apps\VMS_2') 'other drive'
    Assert-True (-not (Test-VmsInstallFolder 'C:\Program Files\PHILCST')) 'spaces'
    Assert-True (-not (Test-VmsInstallFolder ('C:\Pi' + [char]0x00F1 + 'a'))) 'non-ASCII'
    Assert-True (-not (Test-VmsInstallFolder 'relative\path')) 'not absolute'
}

Test-That 'LAN addresses: real adapters, Ethernet first; no loopback, APIPA or virtual' {
    $sample = @(
        [pscustomobject]@{ IPAddress = '127.0.0.1'; InterfaceAlias = 'Loopback Pseudo-Interface 1' },
        [pscustomobject]@{ IPAddress = '169.254.10.2'; InterfaceAlias = 'Ethernet 2' },
        [pscustomobject]@{ IPAddress = '172.20.0.1'; InterfaceAlias = 'vEthernet (WSL)' },
        [pscustomobject]@{ IPAddress = '192.0.2.40'; InterfaceAlias = 'Wi-Fi' },
        [pscustomobject]@{ IPAddress = '198.51.100.7'; InterfaceAlias = 'Ethernet' }
    )
    $result = @(Select-VmsLanAddresses $sample)
    Assert-Equal 2 $result.Count 'count'
    Assert-Equal '198.51.100.7' $result[0] 'Ethernet first'
    Assert-Equal '192.0.2.40' $result[1] 'then Wi-Fi'
}

Test-That 'addresses to open: no :80 on the default port' {
    Assert-Equal 'http://philcst-vms.local/' (Format-VmsUrl 'philcst-vms.local' 80)
    Assert-Equal 'http://198.51.100.7:8080/' (Format-VmsUrl '198.51.100.7' 8080)
}

Test-That 'secrets are removed from text that leaves the PC' {
    $text = Hide-VmsSecrets ("rtsp://admin:S3cret!@198.51.100.20:554/stream1`nAPP_KEY=base64:abc`n" + '{"source_password":"pw","python_api_key":"k","name":"Gate 1"}')
    Assert-True (-not $text.Contains('S3cret')) 'rtsp login'
    Assert-True ($text.Contains('rtsp://***:***@198.51.100.20:554/stream1')) 'address kept'
    Assert-True ($text.Contains('APP_KEY=***')) 'APP_KEY'
    Assert-True ($text.Contains('"source_password":"***"') -and $text.Contains('"python_api_key":"***"') -and $text.Contains('"name":"Gate 1"')) 'json'
}

Test-That 'services: four PHP workers, web, live view, devices, detector (no queue worker)' {
    $paths = Get-VmsPaths 'C:\PHILCST-VMS'
    $services = @(Get-VmsServices $paths)
    Assert-Equal 8 $services.Count 'count'
    Assert-Equal 'PHILCST-PHP1 PHILCST-PHP2 PHILCST-PHP3 PHILCST-PHP4 PHILCST-Web PHILCST-LiveView PHILCST-Devices PHILCST-Detector' (($services | ForEach-Object Name) -join ' ')
    $php = $services | Where-Object Name -eq 'PHILCST-PHP3'
    Assert-Equal '-b 127.0.0.1:9003 -c C:\PHILCST-VMS\config\php.ini' ((Format-VmsServiceParameters $php.Arguments).Replace('/', '\'))
    $detector = $services | Where-Object Name -eq 'PHILCST-Detector'
    Assert-Equal 'camera_service.py' ($detector.Arguments -join ' ')
    Assert-True ($detector.Directory.Replace('/', '\').EndsWith('app\school-vehicle-monitoring-detector')) 'detector folder'
    Assert-Equal 'detector-runtime.log' ([IO.Path]::GetFileName($detector.Log)) 'the log the app shows'
    Assert-Equal 'device-service.stdout.log' ([IO.Path]::GetFileName(($services | Where-Object Name -eq 'PHILCST-Devices').Log))
    Assert-Equal 'go2rtc.log' ([IO.Path]::GetFileName(($services | Where-Object Name -eq 'PHILCST-LiveView').Log))
    Assert-True ((($services | Where-Object Name -eq 'PHILCST-LiveView').Arguments -join ' ').Replace('/', '\').EndsWith('storage\app\go2rtc\go2rtc.yaml')) 'go2rtc config'
}

Test-That 'service parameters: arguments with spaces are quoted' {
    Assert-Equal 'run --config "C:\a b\Caddyfile"' (Format-VmsServiceParameters @('run', '--config', 'C:\a b\Caddyfile'))
}

Test-That 'the install folder is found from a script inside app\deploy\windows' {
    $root = New-TempDir
    try {
        New-Item -ItemType Directory -Force -Path (Join-Path $root 'app/deploy/windows/lib') | Out-Null
        Set-Content -LiteralPath (Join-Path $root 'app/artisan') -Value '<?php'
        $env:PHILCST_VMS_ROOT = $null
        Assert-Equal ((Resolve-Path $root).Path) (Get-VmsRoot -From (Join-Path $root 'app/deploy/windows/lib'))
        Assert-Throws { Get-VmsRoot -From ([IO.Path]::GetTempPath()) } 'was not found'
    } finally { Remove-Item -LiteralPath $root -Recurse -Force }
}

Test-That 'update: .env, the database files and storage move into the new app' {
    $root = New-TempDir
    try {
        $old = Join-Path $root 'app.previous'; $new = Join-Path $root 'app'
        foreach ($dir in "$old/database", "$old/storage/app/camera", "$new/database", "$new/storage/app") { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        Set-Content -LiteralPath "$old/.env" -Value 'APP_KEY=keep-me'
        Set-Content -LiteralPath "$old/database/database.sqlite" -Value 'db'
        Set-Content -LiteralPath "$old/database/database.sqlite-wal" -Value 'wal'
        Set-Content -LiteralPath "$old/storage/app/camera/frame.jpg" -Value 'jpg'
        Set-Content -LiteralPath "$new/storage/app/.gitignore" -Value 'skeleton'
        Set-Content -LiteralPath "$new/database/.gitignore" -Value 'kept'

        Move-VmsData -From $old -To $new

        Assert-Equal 'APP_KEY=keep-me' (Get-Content -LiteralPath "$new/.env" -Raw).Trim()
        Assert-Equal 'db' (Get-Content -LiteralPath "$new/database/database.sqlite" -Raw).Trim()
        Assert-Equal 'wal' (Get-Content -LiteralPath "$new/database/database.sqlite-wal" -Raw).Trim()
        Assert-True (Test-Path -LiteralPath "$new/storage/app/camera/frame.jpg") 'snapshots moved'
        Assert-True (-not (Test-Path -LiteralPath "$new/storage/app/.gitignore")) 'release skeleton replaced'
        Assert-True (Test-Path -LiteralPath "$new/database/.gitignore") 'release files kept'
        Assert-True (-not (Test-Path -LiteralPath "$old/storage")) 'moved, not copied'
    } finally { Remove-Item -LiteralPath $root -Recurse -Force }
}

Test-That 'a program whose result does not matter never stops the script' {
    $ErrorActionPreference = 'Stop'
    Invoke-VmsQuiet -Exe 'this-program-does-not-exist-vms'
    $ls = if ($IsWindows -or $env:OS -eq 'Windows_NT') { 'cmd.exe' } else { 'ls' }
    $args2 = if ($ls -eq 'cmd.exe') { @('/c', 'dir', 'Z:\no-such-folder') } else { @('/no-such-folder') }
    Invoke-VmsQuiet -Exe $ls -Arguments $args2
}

Test-That 'Windows command-line quoting' {
    Assert-Equal 'plain' (ConvertTo-VmsArgument 'plain')
    Assert-Equal '""' (ConvertTo-VmsArgument '')
    Assert-Equal '"two words"' (ConvertTo-VmsArgument 'two words')
    Assert-Equal '"say \"hi\""' (ConvertTo-VmsArgument 'say "hi"')
    Assert-Equal '"C:\a b\\"' (ConvertTo-VmsArgument 'C:\a b\')
    Assert-Equal 'C:\PHILCST-VMS\config\php.ini' (ConvertTo-VmsArgument 'C:\PHILCST-VMS\config\php.ini')
}

Test-That 'programs: stderr and exit codes never stop the script; arguments arrive intact' {
    $ErrorActionPreference = 'Stop'
    if ($IsWindows -or $env:OS -eq 'Windows_NT') {
        $out = Invoke-VmsNative -Exe 'cmd.exe' -Arguments @('/c', 'echo out & echo err 1>&2 & exit 3') -OkCodes @(3) -Quiet
    } else {
        $out = Invoke-VmsNative -Exe '/bin/sh' -Arguments @('-c', 'echo out; echo err 1>&2; exit 3') -OkCodes @(3) -Quiet
    }
    Assert-True (($out -join ' ') -match 'out' -and ($out -join ' ') -match 'err') "output: $out"
    if (-not ($IsWindows -or $env:OS -eq 'Windows_NT')) {
        Assert-Throws { Invoke-VmsNative -Exe '/bin/sh' -Arguments @('-c', 'exit 4') -Quiet } 'exit 4'
        Invoke-VmsQuiet -Exe '/bin/sh' -Arguments @('-c', 'echo boom 1>&2; exit 1')
    }
    $python = (Get-Command python3 -ErrorAction SilentlyContinue).Source
    if ($python) {
        $argv = Invoke-VmsNative -Exe $python -Arguments @('-c', 'import sys, json; print(json.dumps(sys.argv[1:]))', 'Runs the web app''s PHP', 'a "quoted" b', 'C:\x y\', '') -Quiet
        Assert-Equal '["Runs the web app''s PHP", "a \"quoted\" b", "C:\\x y\\", ""]' ($argv -join '')
    }
}

Write-Host ''
Write-Host "$($script:count - $script:failures) of $($script:count) passed"
if ($script:failures -gt 0) { exit 1 }
exit 0
