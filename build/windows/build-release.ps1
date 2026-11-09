# Build-machine entry point (PowerShell 7). All assembly inputs are frozen first.
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$SourceCommit = '', [string]$BuildIdentity = '', [switch]$DevelopmentSnapshot,
    [string]$OutDir = (Join-Path $PSScriptRoot 'dist'),
    [string]$CacheDir = (Join-Path $PSScriptRoot 'cache'),
    [string]$Python = 'python3', [switch]$SkipSelfCheck, [switch]$SkipZip,
    [switch]$Installer, [string]$Iscc = '', [string]$SigningCertificateThumbprint = '',
    [switch]$RedistributionApproved
)
$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSVersion.Major -lt 7) { throw 'Builder requires PowerShell 7; target installation uses built-in PowerShell 5.1.' }
if ($Version -notmatch '^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$') { throw 'Use a semantic version, for example 1.0.0-d2.1.' }
if (-not $DevelopmentSnapshot -and (-not $SigningCertificateThumbprint -or -not $RedistributionApproved)) {
    throw 'Release blocked: explicit source approval, publisher signing certificate and redistribution approval are required. DevelopmentSnapshot is a NON-RELEASE candidate only.'
}
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$source = Join-Path ([IO.Path]::GetTempPath()) ('philcst-source-' + [guid]::NewGuid().ToString('N'))
$arguments = @((Join-Path $PSScriptRoot 'package.py'), 'snapshot', $repo, $source)
if ($DevelopmentSnapshot) { $arguments += '--development' } else { $arguments += @('--commit', $SourceCommit) }
& $Python @arguments
if ($LASTEXITCODE -ne 0) { throw 'Source snapshot refused. Existing work was not staged, discarded or committed.' }
try {
    & (Join-Path $source 'build/windows/assemble.ps1') -SourceRoot $source -Version $Version -BuildIdentity $BuildIdentity -OutDir ([IO.Path]::GetFullPath($OutDir)) -CacheDir ([IO.Path]::GetFullPath($CacheDir)) -Python $Python -SkipSelfCheck:$SkipSelfCheck -SkipZip:$SkipZip -Installer:$Installer -Iscc $Iscc -SigningCertificateThumbprint $SigningCertificateThumbprint -RedistributionApproved:$RedistributionApproved
} finally { Remove-Item -LiteralPath $source -Recurse -Force }
