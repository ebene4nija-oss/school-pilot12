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
AppId={{7C1E4C2A-9F3B-4E77-93A6-2B5D8E1F0A44}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={autopf}\SchoolPilot
DefaultGroupName=SchoolPilot
DisableProgramGroupPage=yes
OutputDir=dist
OutputBaseFilename=SchoolPilot-Setup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
; Writing to Program Files and installing WebView2 both need it, and a lab
; rollout is done by whoever administers the machines anyway.
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
ArchitecturesAllowed=x64compatible
UninstallDisplayName={#AppName}
LicenseFile=
; Inno's Restart Manager would close whatever is holding relay.exe so it can
; replace the file. On a lab machine that means terminating a candidate's exam
; window mid-paper, and on the relay laptop it means killing the process serving
; forty of them. An installer must never do that: InitializeSetup refuses
; instead, and tells the operator what to close.
CloseApplications=no
RestartApplications=no
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
Name: "{commondesktop}\Sit Exam"; Filename: "{app}\{#RelayExe}"; Parameters: "candidate"; \
    Comment: "Sit your exam paper"; Check: IsClient
Name: "{group}\Relay status";     Filename: "{app}\{#RelayExe}"; Parameters: "status"; Check: IsRelay
Name: "{group}\Runbook";          Filename: "{app}\RUNBOOK.md"

[Code]
var
  RolePage: TInputOptionWizardPage;
  ClientPage: TInputQueryWizardPage;
  RelayFingerprint: String;
  ConfigurationFailed: Boolean;

const
  { Inno's preprocessor runs before the Pascal parser and reads any line whose
    first non-blank character is a hash as a directive — even inside a comment
    like this one. So a line-continuation must never begin with a character
    constant. Naming the two used here removes the trap entirely. }
  NewLine = #13#10;
  Blank = #13#10#13#10;
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

{ The values actually used, wherever they came from.
  A command-line parameter wins over the wizard page, and both go through here
  so that an unattended rollout and a person clicking Next are validated by the
  same rules. Reading the page directly is what broke silent installs: in
  /VERYSILENT the pages still exist, still hold their defaults, and
  NextButtonClick still runs. }

function EffectiveRelay: String;
begin
  Result := CmdLineParam('RELAY');
  if (Result = '') and (ClientPage <> nil) then
    Result := Trim(ClientPage.Values[0]);
end;

function EffectiveFingerprint: String;
begin
  Result := CmdLineParam('FINGERPRINT');
  if (Result = '') and (ClientPage <> nil) then
    Result := Trim(ClientPage.Values[1]);
end;

function EffectivePairingCode: String;
begin
  Result := CmdLineParam('PAIR');
  if (Result = '') and (ClientPage <> nil) then
    Result := Trim(ClientPage.Values[2]);
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

{ Run relay.exe, capturing what it printed.

  Inno cannot read a child's stdout, so the output is redirected to a file. The
  redirect goes through a generated .cmd rather than a `cmd /C "..."` argument
  string: relay's arguments are themselves quoted (addresses, fingerprints,
  machine names with spaces), and cmd's rules for nested quotes mangle that
  silently — the child never runs, and the installer cheerfully reports success
  on a machine it never configured. A batch file has no such ambiguity. }
function RunRelay(const Args: String; var Output: String): Boolean;
var
  ResultCode, I: Integer;
  ScriptFile, OutFile: String;
  Lines: TArrayOfString;
begin
  Output := '';
  ScriptFile := ExpandConstant('{tmp}\run-relay.cmd');
  OutFile := ExpandConstant('{tmp}\relay-out.txt');

  SaveStringToFile(ScriptFile,
    '@echo off' + #13#10 +
    '"' + ExpandConstant('{app}\{#RelayExe}') + '" ' + Args + ' > "' + OutFile + '" 2>&1' + #13#10,
    False);

  Log('Running relay: ' + Args);

  Result := Exec(ExpandConstant('{cmd}'), '/C "' + ScriptFile + '"',
                 '', SW_HIDE, ewWaitUntilTerminated, ResultCode) and (ResultCode = 0);

  { Every non-blank line, not just the last one. relay prints multi-line
    messages and its errors end with a newline, so "the last line" is usually
    blank — which is how a real failure reached the log as an empty string and
    left nothing to diagnose. }
  if LoadStringsFromFile(OutFile, Lines) then
    for I := 0 to GetArrayLength(Lines) - 1 do
      if Trim(Lines[I]) <> '' then
        if Output = '' then
          Output := Trim(Lines[I])
        else
          Output := Output + NewLine + Trim(Lines[I]);

  { Written to the setup log, so a school that cannot get a machine working can
    send one file rather than describe a dialog over the phone. }
  Log('Relay exit code ' + IntToStr(ResultCode) + '; said: ' + Output);

  DeleteFile(ScriptFile);
  DeleteFile(OutFile);
end;

{ Setup's own exit code, so a rollout script can tell a configured machine from
  a merely-installed one.

  Inno reports success once the files are copied; an exception raised afterwards
  in CurStepChanged does not change that. A machine that installed but was never
  configured cannot sit a paper, and during an unattended rollout
  /SUPPRESSMSGBOXES swallows the dialog — so without this the room looks done
  and is not. }
procedure ExitProcess(ExitCode: Integer);
  external 'ExitProcess@kernel32.dll stdcall';

procedure FailInstall(const Explanation: String);
begin
  ConfigurationFailed := True;

  Log('SchoolPilot configuration FAILED: ' + Explanation);

  if not WizardSilent then
    MsgBox(Explanation, mbError, MB_OK);
end;

procedure DeinitializeSetup;
begin
  if ConfigurationFailed then
    ExitProcess(1);
end;

{ Is a relay or an exam window running on this machine right now? }
function RelayIsRunning: Boolean;
var
  ResultCode, I: Integer;
  ScriptFile, OutFile: String;
  Lines: TArrayOfString;
begin
  Result := False;

  ScriptFile := ExpandConstant('{tmp}\tasklist.cmd');
  OutFile := ExpandConstant('{tmp}\tasklist.txt');

  SaveStringToFile(ScriptFile,
    '@echo off' + #13#10 +
    'tasklist /FI "IMAGENAME eq {#RelayExe}" > "' + OutFile + '" 2>&1' + #13#10,
    False);

  if Exec(ExpandConstant('{cmd}'), '/C "' + ScriptFile + '"',
          '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    if LoadStringsFromFile(OutFile, Lines) then
      for I := 0 to GetArrayLength(Lines) - 1 do
        if Pos(Lowercase('{#RelayExe}'), Lowercase(Lines[I])) > 0 then
          Result := True;

  DeleteFile(ScriptFile);
  DeleteFile(OutFile);
end;

function InitializeSetup: Boolean;
begin
  Result := True;

  if not RelayIsRunning then
    Exit;

  { Refuse rather than close it. The two things this could be are a candidate
    sitting a paper and a relay serving a room, and neither is something an
    installer may end. }
  Result := False;

  if not WizardSilent then
    MsgBox('SchoolPilot is running on this machine.' + Blank +
           'If an exam is in progress, do not install now — installing would ' +
           'close it.' + Blank +
           'Close the exam window (or press Ctrl-C in the relay''s window) and ' +
           'run this installer again.', mbError, MB_OK);
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
var
  Relay: String;
begin
  Result := True;

  if (CurPageID <> ClientPage.ID) or IsRelay then
    Exit;

  Relay := EffectiveRelay;

  if (Relay = '') or (Lowercase(Relay) = 'https://') then
  begin
    MsgBox('Enter the relay''s address, for example https://10.0.0.4:8443' + NewLine +
           'Run ipconfig on the relay laptop to find it.', mbError, MB_OK);
    Result := False;
    Exit;
  end;

  { §8.3: an https relay with nothing to check it against is worse than useless,
    because the failure is invisible until exam morning. }
  if (Pos('https://', Lowercase(Relay)) = 1) and (EffectiveFingerprint = '') then
  begin
    MsgBox('This relay uses https, so this machine needs its fingerprint.' + Blank +
           'It is printed on the relay laptop when the relay is installed, and by ' +
           'running: relay init', mbError, MB_OK);
    Result := False;
    Exit;
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

  Log('SchoolPilot post-install: role=' + ChosenRole +
      ' relay=' + EffectiveRelay +
      ' fingerprint=' + EffectiveFingerprint +
      ' pair=' + EffectivePairingCode);

  { WebView2 first: without it the exam window cannot open at all (§16). }
  if not WebView2Installed then
  begin
    WebView2Path := ExpandConstant('{tmp}\MicrosoftEdgeWebView2RuntimeInstallerX64.exe');

    if FileExists(WebView2Path) then
    begin
      if not Exec(WebView2Path, '/silent /install', '', SW_HIDE, ewWaitUntilTerminated, ResultCode) or (ResultCode <> 0) then
        MsgBox('The WebView2 runtime failed to install (code ' + IntToStr(ResultCode) + ').' + NewLine +
               'The exam window will not open on this machine until it is installed.',
               mbError, MB_OK);
    end
    else
      MsgBox('This machine does not have the WebView2 runtime, and this installer has no copy of it.' + Blank +
             'The exam window will not open here. Install the WebView2 Evergreen Standalone ' +
             'Runtime and run this again.', mbError, MB_OK);
  end;

  if IsRelay then
  begin
    { §17: the certificate is generated now, at installation, so the fingerprint
      exists before the lab machines are set up. }
    if RunRelay('init --quiet', Output) and (Output <> '') then
    begin
      RelayFingerprint := Output;

      OutFile := CmdLineParam('FINGERPRINTOUT');
      if OutFile <> '' then
        SaveStringToFile(OutFile, RelayFingerprint, False);

      if not WizardSilent then
        MsgBox('This relay''s certificate fingerprint:' + Blank +
               RelayFingerprint + Blank +
               'Every lab machine needs this. It does not change.' + NewLine +
               'Write it down now, or copy it from the Runbook shortcut later.',
               mbInformation, MB_OK);
    end
    else
      FailInstall('The relay could not prepare its certificate, so this machine ' +
                  'cannot serve an exam.' + Blank + Output);
  end
  else
  begin
    Args := 'init --relay "' + EffectiveRelay + '" --name "' + GetComputerNameString + '"';

    if EffectiveFingerprint <> '' then
      Args := Args + ' --fingerprint "' + EffectiveFingerprint + '"';

    if EffectivePairingCode <> '' then
      Args := Args + ' --pair "' + EffectivePairingCode + '"';

    if not RunRelay(Args, Output) then
      FailInstall('This machine could not be configured, so it cannot sit a paper:' + Blank +
                  Output + Blank +
                  'If it mentions pairing: run "relay pair" on the relay laptop and leave ' +
                  'it running, then run this installer again.' + NewLine +
                  'If it mentions the certificate: check the address and fingerprint ' +
                  'against the relay''s screen.');
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
