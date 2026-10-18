<#
.SYNOPSIS
  Copy the built plugin into a local WordPress site (never link it).

.DESCRIPTION
  Copies the runtime files listed in package.json "files" into
  <wwwRoot>\<Site>\wp-content\plugins\<slug>. The site gets a plain copy, so
  deleting or replacing the plugin in WordPress can never touch this repository.

  Run `npm run build` first.

.EXAMPLE
  powershell -File tools/deploy.ps1
  powershell -File tools/deploy.ps1 -Site testwoo.mn
#>
param(
	[string] $Site = 'brand.mn',
	[string] $WwwRoot = 'C:\wamp64\www'
)

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$slug = 'conditional-checkout-fields-and-options-for-woocommerce'
$dest = Join-Path $WwwRoot "$Site\wp-content\plugins\$slug"

if ( -not ( Test-Path ( Join-Path $repo 'build\classic.js' ) ) ) {
	throw 'build/ is missing. Run `npm run build` first.'
}

# Refuse to write through a link: that is how the repository was once deleted.
if ( Test-Path $dest ) {
	$item = Get-Item $dest -Force
	if ( $item.LinkType ) {
		throw "$dest is a $($item.LinkType). Remove only the link (cmd /c rmdir `"$dest`") and run again."
	}
}

$files = ( Get-Content ( Join-Path $repo 'package.json' ) -Raw | ConvertFrom-Json ).files
New-Item -ItemType Directory -Force $dest | Out-Null

foreach ( $entry in $files ) {
	$source = Join-Path $repo $entry
	if ( -not ( Test-Path $source ) ) {
		continue
	}
	$target = Join-Path $dest $entry
	if ( ( Get-Item $source ).PSIsContainer ) {
		# /MIR keeps the copy identical to the repository (removes stale files in the copy only).
		robocopy $source $target /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
		if ( $LASTEXITCODE -ge 8 ) {
			throw "robocopy failed for $entry ($LASTEXITCODE)"
		}
	} else {
		New-Item -ItemType Directory -Force ( Split-Path -Parent $target ) | Out-Null
		Copy-Item $source $target -Force
	}
}

"Deployed $slug to $dest"
