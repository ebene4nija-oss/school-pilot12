<#
    Shared by Install-Relay.ps1 and Install-Candidate.ps1.

    Everything here assumes no internet (docs/offline-cbt-client.md §17: "the
    installer must work from a USB stick with no internet. Assume that is the
    common case"), and assumes the person running it is a school's IT contact
    or an exam officer, not an engineer. Messages are written to be acted on by
    somebody who did not build this.
#>

Set-StrictMode -Version Latest

# WebView2's fixed product code under EdgeUpdate. Stable across versions.
$script:WebView2Guid = '{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}'

function Write-Banner {
    param([Parameter(Mandatory)][string] $Text)

    Write-Host ""
    Write-Host ("  " + ("-" * 66)) -ForegroundColor DarkGray
    Write-Host "  $Text" -ForegroundColor Cyan
    Write-Host ("  " + ("-" * 66)) -ForegroundColor DarkGray
    Write-Host ""
}

function Write-Step { param([string] $Text) Write-Host "  ... $Text" -ForegroundColor Gray }
function Write-Ok   { param([string] $Text) Write-Host "  OK  $Text" -ForegroundColor Green }
function Write-Warn { param([string] $Text) Write-Host "  !   $Text" -ForegroundColor Yellow }

function Assert-Windows {
    # §17: Windows first. Nigerian school labs are Windows, and the kiosk window
    # is WebView2, which is not a thing anywhere else.
    if (-not $IsWindows -and $PSVersionTable.PSVersion.Major -ge 6) {
        throw "This installer is for Windows. The relay runs on Windows lab machines (§17)."
    }
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)

    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw @"
This installer needs to run as Administrator.

Right-click PowerShell, choose "Run as administrator", then run it again.
It needs that to write into Program Files and to install WebView2.
"@
    }
}

function Test-WebView2Installed {
    # Any of the three locations counts: per-machine 64-bit, per-machine 32-bit,
    # and per-user. A machine with a per-user install works fine for the account
    # that has it, which on a lab PC is usually the account sitting the exam.
    foreach ($root in @(
        "HKLM:\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\$script:WebView2Guid",
        "HKLM:\SOFTWARE\Microsoft\EdgeUpdate\Clients\$script:WebView2Guid",
        "HKCU:\SOFTWARE\Microsoft\EdgeUpdate\Clients\$script:WebView2Guid"
    )) {
        if (Test-Path $root) {
            $version = (Get-ItemProperty -Path $root -ErrorAction SilentlyContinue).pv
            if ($version -and $version -ne '0.0.0.0') { return $version }
        }
    }

    return $null
}

function Install-WebView2IfMissing {
    <#
        §16: "WebView2 dependency: present on Windows 11 and current Windows 10,
        but not on older unpatched machines. The installer must bundle the
        evergreen bootstrapper and work without internet."

        So: the standalone installer beside this script is used if present. The
        bootstrapper is NOT used as the primary path because it downloads, and
        a lab with no internet is the case we are designing for.
    #>

    Write-Step "Checking the WebView2 runtime"

    $version = Test-WebView2Installed

    if ($version) {
        Write-Ok "WebView2 already present (version $version)"
        return
    }

    $bundled = Get-ChildItem -Path "$PSScriptRoot\..\webview2" -Filter '*.exe' -ErrorAction SilentlyContinue |
               Select-Object -First 1

    if (-not $bundled) {
        throw @"
WebView2 is not installed on this machine, and no copy was found on this stick.

The exam window cannot open without it. Either:
  - put MicrosoftEdgeWebView2RuntimeInstaller.exe (the STANDALONE installer,
    not the bootstrapper) in the 'webview2' folder beside this script, or
  - install WebView2 on this machine by any other means and run this again.

Download it on a machine that has internet, from:
  https://developer.microsoft.com/microsoft-edge/webview2/
"@
    }

    Write-Step "Installing WebView2 from $($bundled.Name) (no internet needed)"

    # /silent /install is the standalone installer's unattended form.
    $process = Start-Process -FilePath $bundled.FullName `
                             -ArgumentList '/silent', '/install' `
                             -Wait -PassThru

    if ($process.ExitCode -ne 0) {
        throw "WebView2 installer exited with code $($process.ExitCode). The exam window will not open until this succeeds."
    }

    if (-not (Test-WebView2Installed)) {
        throw "WebView2 reported success but is still not registered. Restart this machine and run the installer again."
    }

    Write-Ok "WebView2 installed"
}

function Install-Binary {
    param([Parameter(Mandatory)][string] $InstallDir)

    $source = Join-Path $PSScriptRoot '..\relay.exe' | Resolve-Path -ErrorAction SilentlyContinue

    if (-not $source) {
        throw "relay.exe was not found beside this script. This package is incomplete — do not install from it."
    }

    Write-Step "Copying relay.exe to $InstallDir"

    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null

    $target = Join-Path $InstallDir 'relay.exe'

    # A relay left running from a previous exam would lock the file. Say so
    # rather than failing with a permissions error that means nothing.
    if (Get-Process -Name 'relay' -ErrorAction SilentlyContinue) {
        throw "A relay is still running on this machine. Close it (Ctrl-C in its window) and run this again."
    }

    Copy-Item -Path $source -Destination $target -Force

    Write-Ok "Installed to $target"

    return $target
}

function Add-ToMachinePath {
    param([Parameter(Mandatory)][string] $Directory)

    $current = [Environment]::GetEnvironmentVariable('Path', 'Machine')

    if ($current -split ';' | Where-Object { $_.TrimEnd('\') -eq $Directory.TrimEnd('\') }) {
        Write-Ok "Already on PATH"
        return
    }

    Write-Step "Adding $Directory to PATH"

    [Environment]::SetEnvironmentVariable('Path', "$current;$Directory", 'Machine')

    # So the rest of THIS session can call it too, without a new window.
    $env:Path = "$env:Path;$Directory"

    Write-Ok "On PATH (new windows will pick it up)"
}

function New-Shortcut {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][string] $Target,
        [string] $Arguments = '',
        [switch] $AllUsersDesktop
    )

    $desktop = if ($AllUsersDesktop) {
        [Environment]::GetFolderPath('CommonDesktopDirectory')
    } else {
        [Environment]::GetFolderPath('CommonStartMenu')
    }

    try {
        $shell = New-Object -ComObject WScript.Shell
        $link = $shell.CreateShortcut((Join-Path $desktop "$Name.lnk"))
        $link.TargetPath = $Target
        $link.Arguments = $Arguments
        $link.WorkingDirectory = Split-Path $Target -Parent
        $link.Description = 'SchoolPilot offline CBT'
        $link.Save()

        Write-Ok "Shortcut created: $Name"
    } catch {
        # A missing shortcut is cosmetic. It must never fail an install that
        # otherwise worked — the school can still run the command.
        Write-Warn "Could not create the '$Name' shortcut ($($_.Exception.Message)). Not fatal."
    }
}
