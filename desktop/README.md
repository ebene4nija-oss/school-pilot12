# SchoolPilot Exam — desktop app for the exam hall

Exams are set on the web and sat on the web, the same way as on the mobile app.
This app puts the school's own portal in a locked, fullscreen window on the
hall's PCs. If the internet drops during a paper, the candidate keeps going:
every answer is saved on that PC and sent when the connection is back.

It replaces the old LAN relay (encrypted bundles, pairing, a relay laptop),
which was removed in October 2026. That design is kept for the record in
[`docs/offline-cbt-client.md`](../docs/offline-cbt-client.md) and the relay's
code is in git history (last at `f4e0dca`).

## What it does

| | |
|---|---|
| **Stays on the portal** | Borderless fullscreen, always on top. No address bar or devtools, and no reload, print, save or new-window keys. Links that leave the school's portal, new windows and downloads are refused. Alt-F4 is ignored. |
| **Keeps answers through a dropped line** | The portal's exam page (`web/src/app/student/tests/[attemptId]`) writes each answer to localStorage before sending it, and sends what is held when the line returns. Answers given after time runs out offline go up with the submit, and the server waits for them (`schoolpilot.cbt_offline_grace_minutes`, default 120). This app gives that storage a permanent WebView2 profile, keeps the exam page open, and after a restart reopens any attempt that still holds answers. |
| **Invigilator strip** | School, internet status, time, **Home**, and **Invigilator**, which takes the PIN and offers **Exit app** or **Change school**. Exit warns if any paper on the PC still has unsent answers. |
| **Update notice** | Asks the portal for `/public/downloads/offline-cbt/latest` and shows a notice when a newer release is published, or a stronger one when this version is below the minimum supported. |

Deliberately **not** done: blocking Alt-Tab, Ctrl-Alt-Del or the Windows key.
That needs a keyboard hook that antivirus flags and lab PCs may not allow. The
portal reports a candidate leaving the page instead, for the invigilator
walking the room. No camera, nothing biometric (doc §3).

**One limit to know:** the portal has to load once. A paper already open
survives the internet going down. A PC that is *started* with no internet
waits on a "Waiting for the internet" screen, because the exam page comes from
the server. Answers already held on it are safe either way.

## Install (per PC)

Run `SchoolPilot-Exam-Setup-<version>.exe` as an administrator. On first
launch an invigilator enters the school code (`kings` → `kings.schoolpilot.org.ng`,
or a full https address) and sets a 4–12 digit PIN.

Whole lab, unattended:

```powershell
SchoolPilot-Exam-Setup-1.0.0.exe /VERYSILENT /SCHOOL=kings /PIN=2580
```

Where things live:

- `%ProgramData%\SchoolPilot\Exam\config.json`: school and salted PIN hash, shared by all accounts on the PC.
- `%LOCALAPPDATA%\SchoolPilot\Exam\WebView2`: the browser profile, where held answers live.
- `%LOCALAPPDATA%\SchoolPilot\Exam\state.json`: the last page, and which attempt ids still hold answers. No names or answers.

Uninstalling removes the config but leaves each profile, in case it still
holds unsent answers.

## Build

Needs Rust and [Inno Setup 6](https://jrsoftware.org/isdl.php).

```powershell
cd desktop/exam-app
cargo test                      # address, PIN and resume rules
cargo run                       # debug build, devtools on

cd ../packaging
.\Build-Package.ps1             # tests, release build, installer, SHA-256
```

For Windows 10 PCs without WebView2, put
`MicrosoftEdgeWebView2RuntimeInstallerX64.exe` in `packaging/webview2/` (or
pass `-WebView2 <path>`) and it ships inside the installer.

Releasing: upload the installer to an https location, then register it in
**Super Admin → Downloads** with the version from `exam-app/Cargo.toml` and the
SHA-256 the build prints. Schools download from the stable link
`/api/v1/public/downloads/offline-cbt`.

## Layout

```
exam-app/src/main.rs     window, the two webviews, invigilator and exit logic
exam-app/src/config.rs   school address, PIN, resume target (unit tested)
exam-app/src/pages.rs    the strip, setup and "connecting" pages
packaging/               Inno Setup script and Build-Package.ps1
```
