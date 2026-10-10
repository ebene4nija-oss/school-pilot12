; SchoolPilot Exam — installer for exam-hall PCs.
;
; Built by Build-Package.ps1, which passes AppVersion and stages the exe in
; dist\stage. One kind of machine, one question at first launch (which school,
; and the invigilator PIN), so the wizard itself asks nothing.
;
; Unattended rollout across a lab, configuring the school too:
;   SchoolPilot-Exam-Setup-1.0.0.exe /VERYSILENT /SCHOOL=kings /PIN=2580
; Without /SCHOOL and /PIN the first launch shows the setup screen instead.

#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif

#define AppName      "SchoolPilot Exam"
#define AppPublisher "SchoolPilot"
#define AppExe       "SchoolPilotExam.exe"

[Setup]
; A new AppId: this is a different program from the 0.1.0 relay, and must not
; "upgrade" over it into a broken half-state on a machine that still has it.
AppId={{3A6D2F8E-5B1C-4C7A-9E2D-8F4B6A1C0D93}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
AppComments=Sits CBT papers from the school's SchoolPilot portal in a locked fullscreen window. Answers are kept on the PC if the internet drops.
DefaultDirName={autopf}\SchoolPilot Exam
DefaultGroupName=SchoolPilot
DisableProgramGroupPage=yes
DisableDirPage=yes
OutputDir=dist
OutputBaseFilename=SchoolPilot-Exam-Setup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
ArchitecturesAllowed=x64compatible
UninstallDisplayIcon={app}\{#AppExe}
UninstallDisplayName={#AppName}
; An update must not close an exam in progress. The app is closed by the
; invigilator's PIN, not by an installer.
CloseApplications=no
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Dirs]
; config.json lives here, shared by every Windows account on the PC. Users
; need write access so the invigilator can run first-time setup without admin.
Name: "{commonappdata}\SchoolPilot\Exam"; Permissions: users-modify

[Files]
Source: "dist\stage\{#AppExe}"; DestDir: "{app}"; Flags: ignoreversion
Source: "webview2\MicrosoftEdgeWebView2*.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall skipifsourcedoesntexist

[Icons]
Name: "{commondesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; Comment: "Sit your CBT exam"
Name: "{group}\{#AppName}"; Filename: "{app}\{#AppExe}"

[Run]
Filename: "{app}\{#AppExe}"; Description: "Open {#AppName} now"; Flags: nowait postinstall skipifsilent unchecked

[UninstallDelete]
; The school and PIN. Each user's WebView2 profile in %LOCALAPPDATA% is left:
; it may still hold answers that have not been sent.
Type: files; Name: "{commonappdata}\SchoolPilot\Exam\config.json"

[Code]
const
  NewLine = #13#10;
  WebView2Key = 'SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';
  WebView2Key64 = 'SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';

function CmdLineParam(const Name: String): String;
var
  I: Integer;
  Prefix: String;
begin
  Result := '';
  Prefix := '/' + Uppercase(Name) + '=';
  for I := 1 to ParamCount do
    if Pos(Prefix, Uppercase(ParamStr(I))) = 1 then
    begin
      Result := Copy(ParamStr(I), Length(Prefix) + 1, MaxInt);
      Exit;
    end;
end;

function HasVersion(const Root: Integer; const Key: String): Boolean;
var
  Version: String;
begin
  Result := RegQueryStringValue(Root, Key, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0');
end;

function WebView2Installed: Boolean;
begin
  Result := HasVersion(HKLM, WebView2Key) or HasVersion(HKLM, WebView2Key64) or HasVersion(HKCU, WebView2Key);
end;

procedure InstallWebView2;
var
  FindRec: TFindRec;
  ResultCode: Integer;
  Path: String;
begin
  if WebView2Installed then
    Exit;

  if FindFirst(ExpandConstant('{tmp}\MicrosoftEdgeWebView2*.exe'), FindRec) then
  begin
    Path := ExpandConstant('{tmp}\') + FindRec.Name;
    FindClose(FindRec);
    Log('Installing WebView2 from ' + Path);
    if not Exec(Path, '/silent /install', '', SW_HIDE, ewWaitUntilTerminated, ResultCode) or (ResultCode <> 0) then
      SuppressibleMsgBox('The WebView2 runtime failed to install (code ' + IntToStr(ResultCode) + ').' + NewLine +
        'SchoolPilot Exam needs it to open. Install the Microsoft Edge WebView2 Runtime and try again.',
        mbError, MB_OK, IDOK);
  end
  else
    SuppressibleMsgBox('This PC does not have the Microsoft Edge WebView2 Runtime, which SchoolPilot Exam needs.' + NewLine +
      'Install it from https://developer.microsoft.com/microsoft-edge/webview2/ before the exam.',
      mbInformation, MB_OK, IDOK);
end;

{ /SCHOOL= and /PIN= — set the school now, so a silent lab rollout needs
  nobody at each PC afterwards. The app validates exactly as its setup screen
  does; a bad value is reported, never half-written. }
procedure ConfigureSchool;
var
  School, Pin: String;
  ResultCode: Integer;
begin
  School := Trim(CmdLineParam('SCHOOL'));
  Pin := Trim(CmdLineParam('PIN'));
  if (School = '') and (Pin = '') then
    Exit;

  if (School = '') or (Pin = '') then
  begin
    SuppressibleMsgBox('Give both /SCHOOL= and /PIN= to set the school during install. Nothing was configured; ' +
      'the app will ask on first launch.', mbError, MB_OK, IDOK);
    Exit;
  end;

  if not Exec(ExpandConstant('{app}\{#AppExe}'), '--configure --school "' + School + '" --pin "' + Pin + '"',
              '', SW_HIDE, ewWaitUntilTerminated, ResultCode) or (ResultCode <> 0) then
    SuppressibleMsgBox('Could not set the school to "' + School + '" (code ' + IntToStr(ResultCode) + '). ' +
      'Check the school code and that the PIN is 4 to 12 digits. The app will ask on first launch.',
      mbError, MB_OK, IDOK)
  else
    Log('Configured for school ' + School);
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
  begin
    InstallWebView2;
    ConfigureSchool;
  end;
end;
