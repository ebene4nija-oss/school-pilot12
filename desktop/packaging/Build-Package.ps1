<#
.SYNOPSIS
    Builds SchoolPilot-Exam-Setup-<version>.exe, the installer a school is handed.

.DESCRIPTION
    Release-builds desktop/exam-app, stages the exe, and compiles
    SchoolPilotExam.iss with Inno Setup 6. Prints the SHA-256 that Super Admin >
    Downloads asks for when the release is registered.

    WebView2 ships with Windows 11 and current Windows 10, so it is optional
    here. For older lab PCs, put the Evergreen Standalone installer in
    .\webview2 (or pass -WebView2) and it travels inside setup.exe.

.PARAMETER WebView2
    Path to MicrosoftEdgeWebView2RuntimeInstallerX64.exe to bundle.

.PARAMETER SkipBuild
    Package the release binary that already exists instead of rebuilding.

.EXAMPLE
    .\Build-Package.ps1
    .\Build-Package.ps1 -WebView2 C:\downloads\MicrosoftEdgeWebView2RuntimeInstallerX64.exe
#>
[CmdletBinding()]
param(
    [string] $WebView2,
    [switch] $SkipBuild
)

$ErrorActionPreference = 'Stop'

$appDir = Resolve-Path "$PSScriptRoot\..\exam-app"
$version = (Select-String -Path "$appDir\Cargo.toml" -Pattern '^version\s*=\s*"([^"]+)"' |
            Select-Object -First 1).Matches.Groups[1].Value
$stage = Join-Path $PSScriptRoot 'dist\stage'
$webviewDir = Join-Path $PSScriptRoot 'webview2'

Write-Host "`n  Building SchoolPilot Exam installer $version`n" -ForegroundColor Cyan

if (-not $SkipBuild) {
    Push-Location $appDir
    try {
        cargo test --quiet
        if ($LASTEXITCODE -ne 0) { throw 'cargo test failed' }
        cargo build --release
        if ($LASTEXITCODE -ne 0) { throw 'cargo build failed' }
    } finally {
        Pop-Location
    }
}

$exe = Join-Path $appDir 'target\release\SchoolPilotExam.exe'
if (-not (Test-Path $exe)) { throw "No release binary at $exe. Run without -SkipBuild." }

if (Test-Path $stage) { Remove-Item -Recurse -Force -LiteralPath $stage }
New-Item -ItemType Directory -Force -Path $stage, $webviewDir | Out-Null
Copy-Item $exe $stage

if ($WebView2) {
    if (-not (Test-Path $WebView2)) { throw "WebView2 installer not found: $WebView2" }
    Copy-Item $WebView2 (Join-Path $webviewDir 'MicrosoftEdgeWebView2RuntimeInstallerX64.exe') -Force
}
$bundled = Get-ChildItem $webviewDir -Filter 'MicrosoftEdgeWebView2*.exe' -ErrorAction SilentlyContinue
if ($bundled) { Write-Host "  OK  WebView2 bundled: $($bundled[0].Name)" -ForegroundColor Green }
else { Write-Host '  --  WebView2 not bundled (fine for Windows 11 / current Windows 10)' -ForegroundColor Yellow }

$iscc = @(
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) { throw 'Inno Setup 6 is not installed (https://jrsoftware.org/isdl.php).' }

& $iscc /Q "/DAppVersion=$version" (Join-Path $PSScriptRoot 'SchoolPilotExam.iss')
if ($LASTEXITCODE -ne 0) { throw 'Inno Setup failed' }

$setup = Join-Path $PSScriptRoot "dist\SchoolPilot-Exam-Setup-$version.exe"
$hash = (Get-FileHash $setup -Algorithm SHA256).Hash.ToLower()
"$hash  SchoolPilot-Exam-Setup-$version.exe" | Set-Content -Encoding ascii "$setup.sha256"

Write-Host "`n  OK  $setup" -ForegroundColor Green
Write-Host "      $([math]::Round((Get-Item $setup).Length / 1MB, 1)) MB"
Write-Host "      SHA-256 $hash"
Write-Host "`n  Upload it somewhere https, then register it in Super Admin > Downloads with this version and SHA-256.`n"
