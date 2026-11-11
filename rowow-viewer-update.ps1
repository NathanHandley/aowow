# ROWOW: refresh the aowow database viewer after a ROWOW convert (Windows equivalent of eqwow-viewer-update.sh, for the rowow workstream).
#
#   .\rowow-viewer-update.ps1            full refresh: client data overlay + database regeneration + images
#   .\rowow-viewer-update.ps1 -Quick     database only (skips the icon and map image builds, which take most of the time)
#
# config\config.php must point at the rowow databases (mw_rowow_world etc. and the aowow DB mw_rowow_aowow).

param(
    [switch]$Quick,
    [string]$Exports = 'C:\MWWork\rowow\Assets\WOWExports',
    [string]$Php = "$env:USERPROFILE\.local\php-8.3\php.exe"
)

# Not 'Stop': in Windows PowerShell 5.1 that makes any stderr line from php (aowow's [ERR] notes, e.g. a continent with no world map) end the script
$ErrorActionPreference = 'Continue'
$aowow = $PSScriptRoot
$mpqdata = Join-Path $aowow 'setup\mpqdata\enUS'

Write-Host '== ROWOW viewer update =='
if (-not (Test-Path (Join-Path $Exports 'MPQReady\DBFilesClient'))) { Write-Error "converter export folder not found: $Exports"; exit 1 }

# The existing folders keep their casing (Icons, WORLDMAP)
$iconsDir = (Get-ChildItem (Join-Path $mpqdata 'Interface') -Directory | Where-Object Name -eq 'Icons').FullName
$worldMapDir = (Get-ChildItem (Join-Path $mpqdata 'Interface') -Directory | Where-Object Name -eq 'WorldMap').FullName

Write-Host "[1/4] Overlaying converter output onto $mpqdata"
Copy-Item (Join-Path $Exports 'ExportedDBCFiles\*.dbc') (Join-Path $mpqdata 'DBFilesClient') -Force
Copy-Item (Join-Path $Exports 'MPQReady\DBFilesClient\*.dbc') (Join-Path $mpqdata 'DBFilesClient') -Force
Copy-Item (Join-Path $Exports 'MPQReady\Interface\ICONS\*') $iconsDir -Recurse -Force
Copy-Item (Join-Path $Exports 'MPQReady\Interface\WorldMap\*') $worldMapDir -Recurse -Force

Write-Host '[2/4] Regenerating the aowow database (php aowow --sql), this takes a while'
Push-Location $aowow
try {
    # aowow only reloads its dbc_* cache tables when they're missing: drop them so the new DBCs are read
    & $Php eqwow-drop-dbc-cache.php
    & $Php aowow --sql
    if ($LASTEXITCODE -ne 0) { Write-Error "aowow --sql failed ($LASTEXITCODE)"; exit 1 }
    # Talent calculator tooltips and the Ragnarok character builder's data are static dataset files, not the database
    & $Php aowow --build=talentcalc,robuilder --force
    if ($LASTEXITCODE -ne 0) { Write-Warning '--build=talentcalc,robuilder reported errors (continuing)' }

    if ($Quick) {
        Write-Host '[3/4] Skipping image regeneration (-Quick)'
    } else {
        Write-Host '[3/4] Regenerating icons and zone maps (php aowow --build=simpleimg,img-maps)'
        & $Php aowow --build=simpleimg,img-maps --force
        if ($LASTEXITCODE -ne 0) { Write-Warning '--build reported errors (continuing)' }
    }
} finally {
    Pop-Location
}

Write-Host '[4/4] Clearing the page cache'
Get-ChildItem (Join-Path $aowow 'cache\template') -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force

Write-Host 'Done. Viewer data is up to date.'
