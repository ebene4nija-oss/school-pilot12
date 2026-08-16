<#
.SYNOPSIS
    Builds SchoolPilot-Setup-<version>.exe — the installer a school is handed.

.DESCRIPTION
    One double-clickable setup.exe for both roles. The wizard asks whether the
    machine is the relay (the invigilator's laptop, one per lab) or an exam
    client (every lab PC), and configures it accordingly.

    docs/offline-cbt-client.md §17: it must work from a USB stick with no
    internet. Nothing in the output reaches the network at install time except
    the candidate machines talking to the relay on the lab's own LAN.

    WebView2 is not downloaded by this script, because the machine building the
    package and the machine installing from it are different machines with
    different internet. Fetch it once, pass it with -WebView2, and it travels
    inside setup.exe from then on.

.PARAMETER WebView2
    Path to the WebView2 Evergreen **Standalone** Runtime installer (x64) — not
    the bootstrapper, which downloads at install time and is the one thing a
    school lab cannot do. Compiled into setup.exe.

.PARAMETER SkipBuild
    Package whatever release binary already exists, instead of rebuilding.

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

$stage = Join-Path $PSScriptRoot 'dist\stage'
$webviewDir = Join-Path $PSScriptRoot 'webview2'

Write-Host ""
Write-Host "  Building SchoolPilot installer $version" -ForegroundColor Cyan
Write-Host ""

# ------------------------------------------------------------------- compile
if (-not $SkipBuild) {
    Write-Host "  ... cargo build --release (several minutes)" -ForegroundColor Gray
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

# --------------------------------------------------------------------- stage
# Fresh every time: a stale binary travelling to a school inside an otherwise
# correct installer is the kind of thing nobody finds until exam morning.
if (Test-Path $stage) { Remove-Item -Recurse -Force -LiteralPath $stage }
New-Item -ItemType Directory -Force -Path $stage | Out-Null
New-Item -ItemType Directory -Force -Path $webviewDir | Out-Null

Copy-Item $exe (Join-Path $stage 'relay.exe')

if ($WebView2) {
    if (-not (Test-Path $WebView2)) { throw "WebView2 installer not found: $WebView2" }

    # SchoolPilot.iss expects this exact name.
    Copy-Item $WebView2 (Join-Path $webviewDir 'MicrosoftEdgeWebView2RuntimeInstallerX64.exe') -Force
    Write-Host "  OK  WebView2 runtime bundled" -ForegroundColor Green
}
elseif (-not (Test-Path (Join-Path $webviewDir 'MicrosoftEdgeWebView2RuntimeInstallerX64.exe'))) {
    Set-Content -Path (Join-Path $webviewDir 'PUT-WEBVIEW2-INSTALLER-HERE.txt') -Encoding ascii -Value @"
Put MicrosoftEdgeWebView2RuntimeInstallerX64.exe in this folder.

Get it from a machine that HAS internet:
  https://developer.microsoft.com/microsoft-edge/webview2/

Download the "Evergreen Standalone Installer" (x64), NOT the bootstrapper.
The bootstrapper downloads at install time, which is the one thing a school
lab cannot do.

Without this, the exam window will not open on any machine that does not
already have WebView2 - which is older Windows 10 machines, exactly the ones
a Nigerian school lab is likely to have.
"@
}

# ----------------------------------------------------------------- installer
$iscc = @(
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $iscc) {
    throw @"
Inno Setup is not installed, so there is nothing to compile the installer with.

    winget install JRSoftware.InnoSetup

Then run this again.
"@
}

Write-Host "  ... compiling setup.exe" -ForegroundColor Gray

& $iscc /Q "$PSScriptRoot\SchoolPilot.iss"
if ($LASTEXITCODE -ne 0) { throw "Inno Setup failed (exit $LASTEXITCODE)" }

$setup = Join-Path $PSScriptRoot "dist\SchoolPilot-Setup-$version.exe"
if (-not (Test-Path $setup)) { throw "Inno Setup reported success but produced no installer." }

$hash = (Get-FileHash $setup -Algorithm SHA256).Hash.ToLower()
Set-Content -Path "$setup.sha256" -Encoding ascii -Value $hash

$size = [math]::Round((Get-Item $setup).Length / 1MB, 1)
$bundledWebView2 = Test-Path (Join-Path $webviewDir 'MicrosoftEdgeWebView2RuntimeInstallerX64.exe')

Write-Host ""
Write-Host "  Installer: $setup" -ForegroundColor Green
Write-Host "  Size:      $size MB" -ForegroundColor Green
Write-Host "  SHA-256:   $hash" -ForegroundColor Gray
Write-Host ""
Write-Host "  Copy that one file, and RUNBOOK.md, onto a USB stick." -ForegroundColor Cyan
Write-Host ""

if (-not $bundledWebView2) {
    Write-Host "  WARNING: no WebView2 runtime bundled." -ForegroundColor Yellow
    Write-Host "  The exam window will not open on a lab machine that lacks it." -ForegroundColor Yellow
    Write-Host "  See webview2\PUT-WEBVIEW2-INSTALLER-HERE.txt before shipping to a school." -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "  Not code-signed. SmartScreen will warn on first run — see" -ForegroundColor Yellow
Write-Host "  'SmartScreen' in RUNBOOK.md before a school sees it (§17)." -ForegroundColor Yellow
Write-Host ""
