param([Parameter(Mandatory = $true)][string]$Root, [Parameter(Mandatory = $true)][string]$Payload, [switch]$AllowDevelopmentCandidate, [string]$ExpectedPublisherThumbprint = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
try {
    $port = Assert-VmsInstallPreflight -Root $Root -Payload $Payload -AllowDevelopmentCandidate:$AllowDevelopmentCandidate -ExpectedPublisherThumbprint $ExpectedPublisherThumbprint
    Write-Host "Preflight passed. Local-only web port: $port. No installation data changed."
} catch { Write-Error $_.Exception.Message; exit 1 }
