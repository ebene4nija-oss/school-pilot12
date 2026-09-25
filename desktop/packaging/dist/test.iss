; SchoolPilot Offline CBT — Windows installer
; docs/offline-cbt-client.md §17
;
; One setup.exe for both roles, because a school is handed one stick and a
; wrong-installer-on-the-wrong-machine is a support call nobody needs. The
; wizard asks which this machine is:
;
;   Relay      - the invigilator's laptop. Carries the paper. ONE per lab.
;   Exam client - a lab PC a candidate sits at. All of them.
;
; It must work with no internet (§17 says assume that is the common case), so
; WebView2 travels inside this file rather than being fetched at install time.
;
; Unattended, for rolling out to a room (§17 wants a silent switch):
;
;   setup.exe /VERYSILENT /ROLE=client /RELAY=https://10.0.0.4:8443 ^
;             /FINGERPRINT=e0:fc:... /PAIR=T7RN-DVPN
;
;   setup.exe /VERYSILENT /ROLE=relay /FINGERPRINTOUT=D:\fingerprint.txt

#define AppName        "SchoolPilot Offline CBT"
#define AppPublisher   "SchoolPilot"
#define AppVersion     "0.1.0"
#define RelayExe       "relay.exe"

[Setup]
AppId={{11111111-2222-3333-4444-555555555555}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={localappdata}\SchoolPilotTest
DefaultGroupName=SchoolPilot
DisableProgramGroupPage=yes
OutputDir=dist	est
OutputBaseFilename=TestSetup
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
; Writing to Program Files and installing WebView2 both need it, and a lab
; rollout is done by whoever administers the machines anyway.
PrivilegesRequired=lowest
ArchitecturesInstallIn64BitMode=x64compatible
ArchitecturesAllowed=x64compatible
UninstallDisplayName={#AppName}
LicenseFile=
; Old lab hardware (§16): keep the window honest about what this is.
AppComments=Runs a CBT exam on a school's own computers with no internet during the paper.

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "dist\stage\{#RelayExe}"; DestDir: "{app}"; Flags: ignoreversion
Source: "RUNBOOK.md";            DestDir: "{app}"; Flags: ignoreversion isreadme
; External and optional: the package may be built without it, and Build-Package
; warns loudly when it is. `skipifsourcedoesntexist` keeps the installer
; buildable either way rather than making WebView2 a build dependency.
Source: "webview2\*.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall skipifsourcedoesntexist

[Icons]
; The one a candidate touches. Desktop, all users, because the account sitting
; the exam is not the account that installed it.
Name: "{userdesktop}\Sit Exam TEST"; Filename: "{app}\{#RelayExe}"; Parameters: "candidate"; \
    Comment: "Sit your exam paper"; Check: IsClient
Name: "{group}\Relay status";     Filename: "{app}\{#RelayExe}"; Parameters: "status"; Check: IsRelay
Name: "{group}\Runbook";          Filename: "{app}\RUNBOOK.md"

[Code]
var
  RolePage: TInputOptionWizardPage;
  ClientPage: TInputQueryWizardPage;
  RelayFingerprint: String;

const
  WebView2Key = 'SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';
  WebView2Key64 = 'SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';

{ ---------------------------------------------------------------- helpers }

function CmdLineParam(const Name: String): String;
var
  I: Integer;
  Prefix, Param: String;
begin
  Result := '';
  Prefix := '/' + Uppercase(Name) + '=';

  for I := 1 to ParamCount do
  begin
    Param := ParamStr(I);
    if Pos(Prefix, Uppercase(Param)) = 1 then
    begin
      Result := Copy(Param, Length(Prefix) + 1, MaxInt);
      Exit;
    end;
  end;
end;

function ChosenRole: String;
var
  FromCmdLine: String;
begin
  FromCmdLine := Lowercase(CmdLineParam('ROLE'));

  if (FromCmdLine = 'relay') or (FromCmdLine = 'client') then
  begin
    Result := FromCmdLine;
    Exit;
  end;

  { Page not created yet during an early Check — default to client, which is
    the many-machines case and the safe one: it configures nothing on its own. }
  if RolePage = nil then
    Result := 'client'
  else if RolePage.SelectedValueIndex = 0 then
    Result := 'relay'
  else
    Result := 'client';
end;

function IsRelay: Boolean;
begin
  Result := ChosenRole = 'relay';
end;

function IsClient: Boolean;
begin
  Result := ChosenRole = 'client';
end;

function WebView2Installed: Boolean;
var
  Version: String;
begin
  Result :=
    (RegQueryStringValue(HKLM, WebView2Key, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0')) or
    (RegQueryStringValue(HKLM, WebView2Key64, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0')) or
    (RegQueryStringValue(HKCU, WebView2Key, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0'));
end;

{ Run relay.exe and capture its first line of output.
  Inno cannot read a child's stdout directly, so route it through a file. }
function RunRelayCapture(const Args: String; var Output: String): Boolean;
var
  ResultCode: Integer;
  TempFile: String;
  Lines: TArrayOfString;
begin
  Output := '';
  TempFile := ExpandConstant('{tmp}\relay-out.txt');

  Result := Exec(ExpandConstant('{cmd}'),
                 '/C ""' + ExpandConstant('{app}\{#RelayExe}') + '" ' + Args + ' > "' + TempFile + '" 2>&1"',
                 '', SW_HIDE, ewWaitUntilTerminated, ResultCode) and (ResultCode = 0);

  if LoadStringsFromFile(TempFile, Lines) and (GetArrayLength(Lines) > 0) then
    Output := Trim(Lines[GetArrayLength(Lines) - 1]);

  DeleteFile(TempFile);
end;

{ ------------------------------------------------------------------ wizard }

procedure InitializeWizard;
begin
  RolePage := CreateInputOptionPage(wpSelectDir,
    'What is this machine?',
    'The two roles install differently. Choose carefully — one lab has exactly one relay.',
    'Select the role for this computer:',
    True, False);

  RolePage.Add('Relay — the invigilator''s laptop. It carries the exam paper. ONE per lab.');
  RolePage.Add('Exam client — a lab PC a candidate sits at. Install on EVERY machine.');
  RolePage.SelectedValueIndex := 1;

  ClientPage := CreateInputQueryPage(RolePage.ID,
    'Point this machine at the relay',
    'Taken from the relay laptop. Ask whoever set it up.',
    'The fingerprint is printed when the relay is installed. Without it, this machine ' +
    'cannot tell the real relay from anything else answering on that address.');

  ClientPage.Add('Relay address (e.g. https://10.0.0.4:8443):', False);
  ClientPage.Add('Relay fingerprint:', False);
  ClientPage.Add('Pairing code (from "relay pair"; leave blank to pair later):', False);

  ClientPage.Values[0] := 'https://';
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := (PageID = ClientPage.ID) and IsRelay;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;

  if (CurPageID = ClientPage.ID) and IsClient then
  begin
    if (Trim(ClientPage.Values[0]) = '') or (Lowercase(Trim(ClientPage.Values[0])) = 'https://') then
    begin
      MsgBox('Enter the relay''s address, for example https://10.0.0.4:8443' + #13#10 +
             'Run ipconfig on the relay laptop to find it.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    { §8.3: an https relay with nothing to check it against is worse than
      useless, because the failure is invisible until exam morning. }
    if (Pos('https://', Lowercase(Trim(ClientPage.Values[0]))) = 1) and
       (Trim(ClientPage.Values[1]) = '') then
    begin
      MsgBox('This relay uses https, so this machine needs its fingerprint.' + #13#10 + #13#10 +
             'It is printed on the relay laptop when the relay is installed, and by ' +
             'running: relay init', mbError, MB_OK);
      Result := False;
      Exit;
    end;
  end;
end;

{ --------------------------------------------------------------- installing }

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  WebView2Path, Args, Output, OutFile: String;
begin
  if CurStep <> ssPostInstall then
    Exit;

  { WebView2 first: without it the exam window cannot open at all (§16). }
  if not WebView2Installed then
  begin
    WebView2Path := ExpandConstant('{tmp}\MicrosoftEdgeWebView2RuntimeInstallerX64.exe');

    if FileExists(WebView2Path) then
    begin
      if not Exec(WebView2Path, '/silent /install', '', SW_HIDE, ewWaitUntilTerminated, ResultCode) or (ResultCode <> 0) then
        MsgBox('The WebView2 runtime failed to install (code ' + IntToStr(ResultCode) + ').' + #13#10 +
               'The exam window will not open on this machine until it is installed.',
               mbError, MB_OK);
    end
    else
      MsgBox('This machine does not have the WebView2 runtime, and this installer has no copy of it.' + #13#10 + #13#10 +
             'The exam window will not open here. Install the WebView2 Evergreen Standalone ' +
             'Runtime and run this again.', mbError, MB_OK);
  end;

  if IsRelay then
  begin
    { §17: the certificate is generated now, at installation, so the fingerprint
      exists before the lab machines are set up. }
    if RunRelayCapture('init --quiet', Output) and (Output <> '') then
    begin
      RelayFingerprint := Output;

      OutFile := CmdLineParam('FINGERPRINTOUT');
      if OutFile <> '' then
        SaveStringToFile(OutFile, RelayFingerprint, False);

      if not WizardSilent then
        MsgBox('This relay''s certificate fingerprint:' + #13#10 + #13#10 +
               RelayFingerprint + #13#10 + #13#10 +
               'Every lab machine needs this. It does not change.' + #13#10 +
               'Write it down now, or copy it from the Runbook shortcut later.',
               mbInformation, MB_OK);
    end
    else
      MsgBox('The relay could not prepare its certificate. Installation is not complete.',
             mbError, MB_OK);
  end
  else
  begin
    Args := 'init --relay "' + Trim(ClientPage.Values[0]) + '" --name "' + GetComputerNameString + '"';

    if CmdLineParam('RELAY') <> '' then
      Args := 'init --relay "' + CmdLineParam('RELAY') + '" --name "' + GetComputerNameString + '"';

    if CmdLineParam('FINGERPRINT') <> '' then
      Args := Args + ' --fingerprint "' + CmdLineParam('FINGERPRINT') + '"'
    else if Trim(ClientPage.Values[1]) <> '' then
      Args := Args + ' --fingerprint "' + Trim(ClientPage.Values[1]) + '"';

    if CmdLineParam('PAIR') <> '' then
      Args := Args + ' --pair "' + CmdLineParam('PAIR') + '"'
    else if Trim(ClientPage.Values[2]) <> '' then
      Args := Args + ' --pair "' + Trim(ClientPage.Values[2]) + '"';

    if not RunRelayCapture(Args, Output) then
      MsgBox('This machine was installed, but could not be configured:' + #13#10 + #13#10 +
             Output + #13#10 + #13#10 +
             'If it mentions pairing: run "relay pair" on the relay laptop and leave it ' +
             'running, then run this installer again.' + #13#10 +
             'If it mentions the certificate: check the address and fingerprint against ' +
             'the relay''s screen.', mbError, MB_OK);
  end;
end;

[Run]
Filename: "{app}\RUNBOOK.md"; Description: "Open the runbook"; \
    Flags: postinstall shellexec skipifsilent nowait unchecked

[UninstallDelete]
; The relay's own data — bundle, answers, certificate — is NOT deleted here.
; §13 governs that and `relay purge` is the deliberate act, with a person
; deciding. An uninstaller quietly destroying a room's unsynced answers is
; exactly the failure that section exists to prevent.
Type: files; Name: "{app}\relay-out.txt"
