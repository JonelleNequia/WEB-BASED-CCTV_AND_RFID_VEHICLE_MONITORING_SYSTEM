# Read-only native target/source local-disk requirement. Does not modify services.
param([Parameter(Mandatory=$true)][string]$Root)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if ($env:OS -ne 'Windows_NT' -or -not (Test-VmsAdmin) -or -not (Test-VmsInstallFolder $Root)) {throw 'Known installed local root and native administrator required.'}
$drive=Get-CimInstance Win32_LogicalDisk -Filter ("DeviceID='"+$Root.Substring(0,2)+"'")
if (-not $drive -or $drive.DriveType -ne 3 -or $drive.FileSystem -notin @('NTFS','ReFS')) {throw 'Transfer requires a local fixed ACL-capable disk; no network-share SQLite.'}
