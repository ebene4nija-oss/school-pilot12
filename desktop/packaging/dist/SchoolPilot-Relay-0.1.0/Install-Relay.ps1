<#
.SYNOPSIS
    Installs the SchoolPilot relay on the invigilator's laptop.

.DESCRIPTION
    Run this once, on the ONE machine that will carry the exam paper into the
    lab. It is not run on the candidate PCs — those get Install-Candidate.ps1.

    docs/offline-cbt-client.md §17. Works with no internet: everything it needs
    is beside it on this stick.

    What it does:
      1. Checks the WebView2 runtime, installing the bundled copy if missing.
      2. Copies relay.exe into Program Files.
      3. Puts relay on PATH for everyone on this machine.
      4. Generates the relay's TLS identity and prints the fingerprint (§8.3).

    The fingerprint it prints is what every lab machine must be given. Write it
    down before closing the window — or use -FingerprintOut to have it saved to
    the stick, which is what you want when you are about to walk to forty PCs.

.PARAMETER InstallDir
    Where to install. Defaults to Program Files.

.PARAMETER FingerprintOut
    Also write the fingerprint to this file, for Install-Candidate.ps1 to read.

.PARAMETER Silent
    No prompts, no pauses. For an unattended rollout (§17).

.EXAMPLE
    .\Install-Relay.ps1 -FingerprintOut .\fingerprint.txt
#>

[CmdletBinding()]
param(
    [string] $InstallDir = "$env:ProgramFiles\SchoolPilot",
    [string] $FingerprintOut,
    [switch] $Silent
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\lib\Common.ps1"

Write-Banner "SchoolPilot relay — installing on this machine"

Assert-Windows
Assert-Administrator
Install-WebView2IfMissing
$exe = Install-Binary -InstallDir $InstallDir
Add-ToMachinePath -Directory $InstallDir

# ---------------------------------------------------------------- identity
# §8.3, §17: the certificate is generated now, at installation, not on exam
# morning. The fingerprint has to exist before the lab machines are set up, or
# somebody is typing sixty-four hex characters into forty PCs with candidates
# already sitting down.

Write-Step "Preparing this relay's certificate"

$fingerprint = (& $exe init --quiet | Select-Object -Last 1).Trim()

if (-not $fingerprint) {
    throw "The relay did not return a fingerprint. Installation has not completed."
}

if ($FingerprintOut) {
    $fingerprint | Set-Content -Path $FingerprintOut -Encoding ascii
    Write-Ok "Fingerprint written to $FingerprintOut"
}

New-Shortcut -Name 'SchoolPilot Relay (status)' -Target $exe -Arguments 'status'

Write-Banner "Relay installed"

Write-Host ""
Write-Host "  This relay's certificate fingerprint:" -ForegroundColor Cyan
Write-Host ""
Write-Host "    $fingerprint" -ForegroundColor White
Write-Host ""
Write-Host "  Every candidate machine needs this. It does not change." -ForegroundColor Cyan
Write-Host ""
Write-Host "  Next, on this laptop:" -ForegroundColor Cyan
Write-Host "    relay login --base-url https://<school>.schoolpilot.ng --email <you>"
Write-Host ""
Write-Host "  Then, the day before an exam:"
Write-Host "    relay provision --exam <id>"
Write-Host "    relay pair                     # leave running while you set up the lab"
Write-Host ""
Write-Host "  On exam morning:"
Write-Host "    relay serve --lan"
Write-Host ""

if (-not $Silent) { Read-Host "Press Enter to close" | Out-Null }
