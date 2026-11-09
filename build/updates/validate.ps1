# Read-only D3 interface. Caller supplies installation-trusted tools/state/public keys.
# PowerShell 5.1 compatible. Never executes candidate code or installs an update.
param([Parameter(Mandatory=$true)][string]$Manifest,
 [Parameter(Mandatory=$true)][string]$Package,
 [Parameter(Mandatory=$true)][string]$State,
 [Parameter(Mandatory=$true)][string]$TrustStore,
 [Parameter(Mandatory=$true)][string]$Python,
 [Parameter(Mandatory=$true)][string]$Php,
 [string]$PhpIni='', [ValidateSet('stable','prerelease','development')][string]$Channel='stable',
 [ValidateSet('local','downloaded')][string]$Delivery='local', [switch]$FixtureTrust)
$ErrorActionPreference='Stop'
$arguments=@('-B',(Join-Path $PSScriptRoot 'validate.py'),'--manifest',$Manifest,'--package',$Package,'--state',$State,'--trust-store',$TrustStore,'--php',$Php,'--channel',$Channel,'--delivery',$Delivery)
if ($PhpIni) { $arguments+=@('--php-ini',$PhpIni) }
if ($FixtureTrust) { $arguments+='--fixture-trust' }
& $Python @arguments
exit $LASTEXITCODE
