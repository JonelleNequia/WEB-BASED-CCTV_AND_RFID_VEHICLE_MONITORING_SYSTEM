; PHILCST Vehicle Monitoring - Windows installer (Inno Setup 6.3 or newer).
;
; Built by build/windows/build-release.ps1 (needs ISCC.exe, Windows only):
;   ISCC.exe /DAppVersion=<version> /DSourceDir=<dist>\PHILCST-VMS /O<dist> PHILCST-VMS.iss
; Output: PHILCST-VMS-Setup-<version>.exe
;
; What it does: copies the self-contained bundle (app, PHP, Python, Caddy,
; go2rtc, NSSM) into C:\PHILCST-VMS, then runs app\deploy\windows\install.ps1
; (port, .env, database, services, scheduled tasks, firewall, power) and
; shows the address to open and the first sign-in.
;
; Silent (CI, Phase 6):
;   PHILCST-VMS-Setup-<version>.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /DIR=C:\PHILCST-VMS /TASKS="networkprivate"
;   unins000.exe /VERYSILENT /SUPPRESSMSGBOXES /REMOVEDATA=yes      (in {app}\uninstall)

#ifndef AppVersion
  #define AppVersion "0.0.0-dev"
#endif
#ifndef SourceDir
  #define SourceDir "..\dist\PHILCST-VMS"
#endif

[Setup]
AppId={{6F1C2B7E-4E0A-4C55-9A8B-2D3F5E7A9C10}
AppName=PHILCST Vehicle Monitoring
AppVersion={#AppVersion}
AppVerName=PHILCST Vehicle Monitoring {#AppVersion}
AppPublisher=PHILCST
DefaultDirName=C:\PHILCST-VMS
DisableDirPage=no
DirExistsWarning=no
DefaultGroupName=PHILCST VMS
DisableProgramGroupPage=yes
AllowNoIcons=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=PHILCST-VMS-Setup-{#AppVersion}
Compression=lzma2/normal
SolidCompression=yes
LZMANumBlockThreads=4
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartIfNeededByRun=no
UninstallFilesDir={app}\uninstall
UninstallDisplayName=PHILCST Vehicle Monitoring

[Tasks]
Name: "networkprivate"; Description: "Set this PC's network connection to Private (other PCs on the LAN can then open the system)"
Name: "renamepc"; Description: "Rename this computer to PHILCST-VMS, to open it as http://philcst-vms.local (needs a restart)"; Flags: unchecked
Name: "desktopicon"; Description: "Desktop shortcut to open the system"

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
; "Open PHILCST VMS.url" is written by install.ps1 (it knows the port).
Name: "{group}\Open PHILCST VMS"; Filename: "{app}\Open PHILCST VMS.url"
Name: "{group}\Status"; Filename: "{app}\app\deploy\windows\status.bat"
Name: "{group}\Start all"; Filename: "{app}\app\deploy\windows\start-all.bat"
Name: "{group}\Stop all"; Filename: "{app}\app\deploy\windows\stop-all.bat"
Name: "{group}\Restart all"; Filename: "{app}\app\deploy\windows\restart-all.bat"
Name: "{group}\Back up now"; Filename: "{app}\app\deploy\windows\backup-now.bat"
Name: "{group}\Restore a backup"; Filename: "{app}\app\deploy\windows\restore.bat"
Name: "{group}\Update from a release ZIP"; Filename: "{app}\app\deploy\windows\update.bat"
Name: "{group}\Collect logs"; Filename: "{app}\app\deploy\windows\collect-logs.bat"
Name: "{group}\Gate kiosk shortcut"; Filename: "{app}\app\deploy\windows\gate-kiosk-shortcut.bat"
Name: "{group}\Install folder"; Filename: "{app}"
Name: "{group}\Uninstall PHILCST VMS"; Filename: "{uninstallexe}"
Name: "{commondesktop}\PHILCST VMS"; Filename: "{app}\Open PHILCST VMS.url"; Tasks: desktopicon

[Run]
Filename: "{app}\Open PHILCST VMS.url"; Description: "Open PHILCST VMS in the browser"; Flags: postinstall shellexec nowait skipifsilent; Check: InstallSucceeded

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\app\deploy\windows\uninstall.ps1"" -Root ""{app}"" {code:RemoveDataSwitch}"; Flags: runhidden waituntilterminated; RunOnceId: "PhilcstRemoveServices"

[Code]
var
  InstallOk: Boolean;
  ResultText: String;
  ResultMemo: TNewMemo;
  RemoveData: Boolean;

function IsSimplePath(Path: String): Boolean;
var
  I: Integer;
  C: Char;
begin
  { Letters, digits, - _ . \ and the drive colon: the services' command lines stay simple. }
  Result := (Length(Path) >= 3) and (Path[2] = ':');
  for I := 1 to Length(Path) do
  begin
    C := Path[I];
    if not (((C >= 'A') and (C <= 'Z')) or ((C >= 'a') and (C <= 'z')) or ((C >= '0') and (C <= '9'))
      or (C = '-') or (C = '_') or (C = '.') or (C = '\') or ((C = ':') and (I = 2))) then
      Result := False;
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (CurPageID = wpSelectDir) and not IsSimplePath(WizardDirValue) then
  begin
    MsgBox('Choose a folder without spaces or special letters, for example C:\PHILCST-VMS.', mbError, MB_OK);
    Result := False;
  end;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
begin
  { Installing over an earlier version: stop its services so their files can be replaced. }
  Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
    '-NoProfile -ExecutionPolicy Bypass -Command "Get-Service -Name ''PHILCST-*'' -ErrorAction SilentlyContinue | Stop-Service -Force -ErrorAction SilentlyContinue"',
    '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Result := '';
end;

procedure InitializeWizard();
begin
  ResultMemo := TNewMemo.Create(WizardForm);
  ResultMemo.Parent := WizardForm.FinishedPage;
  ResultMemo.ReadOnly := True;
  ResultMemo.ScrollBars := ssVertical;
  ResultMemo.WordWrap := True;
  ResultMemo.Visible := False;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  Params: String;
  Text: AnsiString;
begin
  if CurStep = ssPostInstall then
  begin
    WizardForm.StatusLabel.Caption := 'Setting up the services, database and firewall (a few minutes)...';
    Params := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\app\deploy\windows\install.ps1') + '" -Root "' + ExpandConstant('{app}') + '"';
    if WizardIsTaskSelected('networkprivate') then
      Params := Params + ' -SetNetworkPrivate';
    if WizardIsTaskSelected('renamepc') then
      Params := Params + ' -ComputerName PHILCST-VMS';
    if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Params, ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode) then
      ResultCode := -1;
    InstallOk := (ResultCode = 0);

    if LoadStringFromFile(ExpandConstant('{app}\config\install-result.txt'), Text) then
      ResultText := String(Text)
    else
      ResultText := 'The setup did not finish. See the logs folder in ' + ExpandConstant('{app}') + ' and run the installer again.';
    Log(ResultText);
    if (not InstallOk) and (not WizardSilent) then
      MsgBox(ResultText, mbError, MB_OK);
  end;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = wpFinished then
  begin
    if InstallOk then
      WizardForm.FinishedLabel.Caption := 'PHILCST Vehicle Monitoring is installed. Write down the sign-in below (it is shown once).'
    else
      WizardForm.FinishedLabel.Caption := 'The files are installed, but the setup did not finish:';
    WizardForm.FinishedLabel.Height := ScaleY(36);
    ResultMemo.Left := WizardForm.FinishedLabel.Left;
    ResultMemo.Top := WizardForm.FinishedLabel.Top + WizardForm.FinishedLabel.Height + ScaleY(6);
    ResultMemo.Width := WizardForm.FinishedLabel.Width;
    ResultMemo.Height := ScaleY(170);
    ResultMemo.Text := ResultText;
    ResultMemo.Visible := True;
    WizardForm.RunList.Top := ResultMemo.Top + ResultMemo.Height + ScaleY(8);
  end;
end;

function InstallSucceeded(): Boolean;
begin
  Result := InstallOk;
end;

function NeedRestart(): Boolean;
begin
  Result := InstallOk and WizardIsTaskSelected('renamepc');
end;

function InitializeUninstall(): Boolean;
begin
  Result := True;
  if UninstallSilent then
    RemoveData := CompareText(ExpandConstant('{param:REMOVEDATA|no}'), 'yes') = 0
  else
    RemoveData := MsgBox('Also delete the data (database, snapshots, backups, settings)?' + #13#10#13#10 +
      'Choose No to keep it: installing again brings everything back.', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES;
end;

function RemoveDataSwitch(Param: String): String;
begin
  if RemoveData then
    Result := '-RemoveData'
  else
    Result := '';
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if (CurUninstallStep = usPostUninstall) and RemoveData then
    DelTree(ExpandConstant('{app}'), True, True, True);
end;
