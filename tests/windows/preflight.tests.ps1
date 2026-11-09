$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../../deploy/windows/lib/common.ps1')
$count = 0
function Refuses([scriptblock]$Test, [string]$Message) {
    $script:count++
    try { & $Test } catch { if ($_.Exception.Message -match $Message) { return }; throw }
    throw 'Expected preflight refusal.'
}
Assert-VmsPlatform -Version '10.0' -Architecture 'AMD64' -Elevated $true -LocalDrive $true -FreeBytes 10GB -RequiredBytes 2GB
$count++
Refuses { Assert-VmsPlatform -Version '6.3' -Architecture 'AMD64' -Elevated $true -LocalDrive $true -FreeBytes 10GB -RequiredBytes 2GB } 'Windows 10'
Refuses { Assert-VmsPlatform -Version '10.0' -Architecture 'ARM64' -Elevated $true -LocalDrive $true -FreeBytes 10GB -RequiredBytes 2GB } 'native'
Refuses { Assert-VmsPlatform -Version '10.0' -Architecture 'x86' -Elevated $true -LocalDrive $true -FreeBytes 10GB -RequiredBytes 2GB } 'native'
Refuses { Assert-VmsPlatform -Version '10.0' -Architecture 'AMD64' -Elevated $false -LocalDrive $true -FreeBytes 10GB -RequiredBytes 2GB } 'administrator'
Refuses { Assert-VmsPlatform -Version '10.0' -Architecture 'AMD64' -Elevated $true -LocalDrive $false -FreeBytes 10GB -RequiredBytes 2GB } 'local'
Refuses { Assert-VmsPlatform -Version '10.0' -Architecture 'AMD64' -Elevated $true -LocalDrive $true -FreeBytes 1GB -RequiredBytes 2GB } 'space'
if (-not (Test-VmsOwnedCommand '"C:\PHILCST-VMS\runtime\nssm\nssm.exe"' 'C:\PHILCST-VMS\runtime\nssm\nssm.exe')) { throw 'Owned command rejected' };$count++
if (Test-VmsOwnedCommand 'C:\Other\nssm.exe' 'C:\PHILCST-VMS\runtime\nssm\nssm.exe') { throw 'Unrelated command accepted' };$count++
$work = Join-Path ([IO.Path]::GetTempPath()) ('philcst-preflight-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $work | Out-Null
try {
    [IO.File]::WriteAllText((Join-Path $work 'file.txt'), 'fixture')
    [IO.File]::WriteAllText((Join-Path $work '.hidden'), 'hidden dependency fixture')
    [IO.File]::WriteAllText((Join-Path $work 'BUILD-INFO.json'), '{"architecture":"win_amd64","mode":"local-only","channel":"development"}')
    $files = @{}
    foreach ($name in 'file.txt','.hidden','BUILD-INFO.json') { $files[$name] = @{sha256=(Get-FileHash (Join-Path $work $name) -Algorithm SHA256).Hash.ToLowerInvariant();bytes=(Get-Item -Force (Join-Path $work $name)).Length} }
    @{ format=1; files=$files; total_bytes=100 } | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $work 'CONTENT-MANIFEST.json')
    Refuses { Test-VmsPayload -Payload $work } 'NON-RELEASE'
    Test-VmsPayload -Payload $work -AllowDevelopmentCandidate | Out-Null;$count++
    [IO.File]::WriteAllText((Join-Path $work 'file.txt'),'corrupt')
    Refuses { Test-VmsPayload -Payload $work -AllowDevelopmentCandidate } 'Corrupt'
    Remove-Item (Join-Path $work 'file.txt')
    Refuses { Test-VmsPayload -Payload $work -AllowDevelopmentCandidate } 'Missing dependency'
} finally { Remove-Item $work -Recurse -Force }
Write-Host "$count preflight policy/integrity checks passed; native Windows calls NOT RUN."
