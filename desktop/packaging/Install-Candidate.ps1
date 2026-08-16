<#
.SYNOPSIS
    Installs the SchoolPilot exam client on one candidate PC.

.DESCRIPTION
    Run this on EVERY machine that will be used to sit an exam — not a sample
    (docs/offline-cbt-client.md §8.4). Once the relay has any paired machine,
    an unpaired one is refused, so a PC missed here is a PC that cannot sit the
    paper.

    Works with no internet. It talks only to the relay, on the lab's own
    network.

    A candidate machine holds an address, a fingerprint and its own device
    token. No staff password, no school data, no exam paper — the paper lives on
    the relay and only on the relay (§3).

.PARAMETER RelayUrl
    The relay's address on the lab network, e.g. https://10.0.0.4:8443

.PARAMETER Fingerprint
    The relay's certificate fingerprint, printed by Install-Relay.ps1. Either
    paste it, or use -FingerprintFile to read the file that installer wrote.

.PARAMETER FingerprintFile
    Read the fingerprint from a file instead (what -FingerprintOut wrote).

.PARAMETER PairingCode
    The code shown by `relay pair` on the relay. Pairing needs the relay to be
    in pairing mode right now, so run `relay pair` there first and leave it
    running while you walk the room.

.PARAMETER MachineName
    What the relay should call this PC. Defaults to the Windows computer name,
    which usually matches the school's own asset label.

.PARAMETER Silent
    No prompts, no pauses. For an unattended rollout (§17).

.EXAMPLE
    .\Install-Candidate.ps1 -RelayUrl https://10.0.0.4:8443 `
                            -FingerprintFile .\fingerprint.txt `
                            -PairingCode T7RN-DVPN

.EXAMPLE
    # Unattended, across a lab, from a share or a stick:
    .\Install-Candidate.ps1 -RelayUrl https://10.0.0.4:8443 `
                            -FingerprintFile .\fingerprint.txt `
                            -PairingCode T7RN-DVPN -Silent
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $RelayUrl,
    [string] $Fingerprint,
    [string] $FingerprintFile,
    [string] $PairingCode,
    [string] $MachineName = $env:COMPUTERNAME,
    [string] $InstallDir = "$env:ProgramFiles\SchoolPilot",
    [switch] $Silent
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\lib\Common.ps1"

Write-Banner "SchoolPilot exam client — installing on $MachineName"

Assert-Windows
Assert-Administrator

if (-not $Fingerprint -and $FingerprintFile) {
    if (-not (Test-Path $FingerprintFile)) {
        throw "Fingerprint file not found: $FingerprintFile"
    }
    $Fingerprint = (Get-Content $FingerprintFile -Raw).Trim()
}

# §8.3: an https relay with nothing to check it against is worse than useless,
# because the failure is invisible. Refuse here rather than installing a machine
# that will fail on exam morning.
if ($RelayUrl -like 'https://*' -and -not $Fingerprint) {
    throw @"
No fingerprint given, and the relay address is https.

This machine would have no way to tell the real relay from anything else that
answers on that address. Get the fingerprint from the relay laptop — it is
printed by Install-Relay.ps1, and by 'relay init' — and pass it with
-Fingerprint or -FingerprintFile.
"@
}

Install-WebView2IfMissing
$exe = Install-Binary -InstallDir $InstallDir
Add-ToMachinePath -Directory $InstallDir

# ------------------------------------------------------------------ configure
# §5.1: exam morning involves no configuration. Everything the machine needs is
# recorded now, so that on the day it is one command with no arguments.

Write-Step "Recording the relay address and fingerprint"

$arguments = @('init', '--relay', $RelayUrl, '--name', $MachineName)

if ($Fingerprint)  { $arguments += @('--fingerprint', $Fingerprint) }
if ($PairingCode)  { $arguments += @('--pair', $PairingCode) }

& $exe @arguments

if ($LASTEXITCODE -ne 0) {
    throw @"
Configuring this machine failed (exit code $LASTEXITCODE).

If it mentions pairing: run 'relay pair' on the relay laptop and leave it
running, then run this installer again with the code it shows.

If it mentions the certificate: check the fingerprint against the relay's
screen, and check this machine can reach $RelayUrl at all.
"@
}

New-Shortcut -Name 'Sit Exam' -Target $exe -Arguments 'candidate' -AllUsersDesktop

Write-Banner "This machine is ready"

Write-Host ""
if ($PairingCode) {
    Write-Host "  Paired with the relay as `"$MachineName`"." -ForegroundColor Green
} else {
    Write-Warn "NOT paired yet."
    Write-Host "      Run 'relay pair' on the relay, then run this installer again"
    Write-Host "      with -PairingCode. Until then this machine cannot sit a paper"
    Write-Host "      once the room is paired (§8.4)."
}

Write-Host ""
Write-Host "  On exam morning, the candidate opens 'Sit Exam' on the desktop," -ForegroundColor Cyan
Write-Host "  or you run:  relay candidate" -ForegroundColor Cyan
Write-Host ""

if (-not $Silent) { Read-Host "Press Enter to close" | Out-Null }
