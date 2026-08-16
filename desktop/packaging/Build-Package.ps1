<#
.SYNOPSIS
    Builds the USB stick a school is handed.

.DESCRIPTION
    Produces desktop/packaging/dist/SchoolPilot-Relay-<version>/ containing a
    release relay.exe, both installers, and the runbook — then zips it.

    docs/offline-cbt-client.md §17: it must work from a stick with no internet.
    Nothing in the output reaches the network at install time except the
    candidate machines talking to the relay on the lab's own LAN.

    WebView2 is NOT downloaded by this script, because the machine building the
    package and the machine installing from it are different machines with
    different internet. Fetch it once, drop it in, and it travels with the
    package from then on — see -WebView2 and the warning at the end.

.PARAMETER WebView2
    Path to MicrosoftEdgeWebView2RuntimeInstaller.exe (the STANDALONE
    installer, not the bootstrapper — the bootstrapper downloads, which is
    exactly what a lab cannot do). Copied into the package.

.PARAMETER SkipBuild
    Package whatever release binary already exists.

.EXAMPLE
    .\Build-Package.ps1 -WebView2 C:\downloads\MicrosoftEdgeWebView2RuntimeInstallerX64.exe
#>

[CmdletBinding()]
param(
    [string] $WebView2,
    [switch] $SkipBuild
)

$ErrorActionPreference = 'Stop'

$relayDir = Resolve-Path "$PSScriptRoot\..\relay"
$version = (Select-String -Path "$relayDir\Cargo.toml" -Pattern '^version\s*=\s*"([^"]+)"' |
            Select-Object -First 1).Matches.Groups[1].Value

$stage = Join-Path $PSScriptRoot "dist\SchoolPilot-Relay-$version"

Write-Host ""
Write-Host "  Building SchoolPilot relay package $version" -ForegroundColor Cyan
Write-Host ""

if (-not $SkipBuild) {
    Write-Host "  ... cargo build --release (this takes a few minutes)" -ForegroundColor Gray
    Push-Location $relayDir
    try {
        cargo build --release
        if ($LASTEXITCODE -ne 0) { throw "cargo build failed" }
    } finally {
        Pop-Location
    }
}

$exe = Join-Path $relayDir 'target\release\relay.exe'
if (-not (Test-Path $exe)) { throw "No release binary at $exe. Run without -SkipBuild." }

# A fresh stage every time: a stale file from a previous version travelling to a
# school inside an otherwise-correct package is the kind of thing nobody finds
# until exam morning.
if (Test-Path $stage) { Remove-Item -Recurse -Force -LiteralPath $stage }
New-Item -ItemType Directory -Force -Path "$stage\lib" | Out-Null
New-Item -ItemType Directory -Force -Path "$stage\webview2" | Out-Null

Copy-Item $exe "$stage\relay.exe"
Copy-Item "$PSScriptRoot\Install-Relay.ps1"     $stage
Copy-Item "$PSScriptRoot\Install-Candidate.ps1" $stage
Copy-Item "$PSScriptRoot\lib\Common.ps1"        "$stage\lib"
Copy-Item "$PSScriptRoot\RUNBOOK.md"            $stage

if ($WebView2) {
    if (-not (Test-Path $WebView2)) { throw "WebView2 installer not found: $WebView2" }
    Copy-Item $WebView2 "$stage\webview2\"
    Write-Host "  OK  WebView2 runtime bundled" -ForegroundColor Green
} else {
    Set-Content -Path "$stage\webview2\PUT-WEBVIEW2-INSTALLER-HERE.txt" -Encoding ascii -Value @"
Put MicrosoftEdgeWebView2RuntimeInstallerX64.exe in this folder.

Get it from a machine that HAS internet:
  https://developer.microsoft.com/microsoft-edge/webview2/

Download the "Evergreen Standalone Installer" (x64), NOT the bootstrapper.
The bootstrapper downloads at install time, which is the one thing a school
lab cannot do.

Without this, installation will stop on any machine that does not already
have WebView2 - which is older Windows 10 machines, exactly the ones a
Nigerian school lab is likely to have.
"@
}

$hash = (Get-FileHash "$stage\relay.exe" -Algorithm SHA256).Hash.ToLower()
Set-Content -Path "$stage\relay.exe.sha256" -Encoding ascii -Value $hash

$zip = "$stage.zip"
if (Test-Path $zip) { Remove-Item -Force -LiteralPath $zip }
Compress-Archive -Path "$stage\*" -DestinationPath $zip

$size = [math]::Round((Get-Item $zip).Length / 1MB, 1)

Write-Host ""
Write-Host "  Package:  $zip" -ForegroundColor Green
Write-Host "  Size:     $size MB" -ForegroundColor Green
Write-Host "  relay.exe SHA-256: $hash" -ForegroundColor Gray
Write-Host ""

if (-not $WebView2) {
    Write-Host "  WARNING: no WebView2 runtime bundled." -ForegroundColor Yellow
    Write-Host "  This package will FAIL on a lab machine that does not already have it." -ForegroundColor Yellow
    Write-Host "  See webview2\PUT-WEBVIEW2-INSTALLER-HERE.txt before shipping to a school." -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "  Not code-signed. Windows SmartScreen will warn on first run —" -ForegroundColor Yellow
Write-Host "  see 'SmartScreen' in RUNBOOK.md before a school sees it (§17)." -ForegroundColor Yellow
Write-Host ""
