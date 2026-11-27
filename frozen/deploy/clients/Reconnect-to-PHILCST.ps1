# Explicit D5D client handoff. Reuses D4 ownership/TLS checks; no server software.
param([Parameter(Mandatory=$true)][string]$OldServerAddress,
 [Parameter(Mandatory=$true)][string]$ServerAddress,
 [Parameter(Mandatory=$true)][string]$RootCertificate,
 [Parameter(Mandatory=$true)][string]$RootFingerprint,
 [switch]$OldServerIsolated,
 [ValidateSet('Hosts','DNS')][string]$NameResolution='Hosts')
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'client-common.ps1')
Assert-ClientAdmin
if (-not $OldServerIsolated) {throw 'Verify the retired server is physically/network isolated before client handoff; lack of response is not proof.'}
$receipt=Join-Path $env:ProgramData 'PHILCST-VMS-Client\connection.json'
if (-not (Test-Path -LiteralPath $receipt)) {throw 'No owned client mapping. Use verified D4 Connect with the new server address instead.'}
$old=Get-Content -LiteralPath $receipt -Raw | ConvertFrom-Json
if ($old.address -ne $OldServerAddress) {throw 'Recorded old address differs. Inspect stale/conflicting enrollment before modifying it.'}
if ($old.fingerprint -ne $RootFingerprint.Replace(':','').ToLowerInvariant()) {throw 'Issuer rotation needs separately verified Remove and re-enrollment; no trust was replaced.'}
& (Join-Path $PSScriptRoot 'Connect-to-PHILCST.ps1') -Action Connect -ServerAddress $ServerAddress -RootCertificate $RootCertificate -RootFingerprint $RootFingerprint -NameResolution $NameResolution
