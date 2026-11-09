; D2 bootstrapper: extract the verified complete ZIP into TEMP, preflight BEFORE root mutation.
#ifndef AppVersion
  #define AppVersion "0.0.0-dev"
#endif
#ifndef PayloadZip
  #error PayloadZip must be the verified complete offline ZIP
#endif
#ifndef Candidate
  #define Candidate "True"
#endif
[Setup]
AppId={{6F1C2B7E-4E0A-4C55-9A8B-2D3F5E7A9C10}
AppName=PHILCST Vehicle Monitoring
AppVersion={#AppVersion}
DefaultDirName=C:\PHILCST-VMS
DefaultGroupName=PHILCST VMS
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=PHILCST-VMS-Setup-{#AppVersion}
Compression=lzma2/fast
SolidCompression=yes
SetupLogging=yes
CloseApplications=no
UninstallFilesDir={app}\uninstall
WizardStyle=modern
[Files]
Source: "{#PayloadZip}"; DestName: "payload.zip"; Flags: dontcopy
[Icons]
Name: "{group}\Open PHILCST VMS"; Filename: "{app}\Open PHILCST VMS.url"
Name: "{commondesktop}\Open PHILCST VMS"; Filename: "{app}\Open PHILCST VMS.url"
Name: "{group}\Status"; Filename: "{app}\app\deploy\windows\status.bat"
Name: "{group}\Start"; Filename: "{app}\app\deploy\windows\start-all.bat"
Name: "{group}\Stop"; Filename: "{app}\app\deploy\windows\stop-all.bat"
[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\app\deploy\windows\uninstall.ps1"" -Root ""{app}"" {code:RemoveDataSwitch}"; Flags: runhidden waituntilterminated; RunOnceId: "PhilcstRemoveOwnedServices"
[Code]
var
  Prepared: Boolean;
  Payload: String;
  RemoveData: Boolean;
function RunPowerShell(Arguments: String): Integer;
var Code: Integer;
begin
  if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), '-NoProfile -ExecutionPolicy Bypass ' + Arguments, '', SW_HIDE, ewWaitUntilTerminated, Code) then Code := -1;
  Result := Code;
end;
function ModeArguments(): String;
begin
  Result := '';
  if CompareText('{#Candidate}', 'True') = 0 then Result := ' -AllowDevelopmentCandidate'
  else Result := ' -ExpectedPublisherThumbprint "' + ExpandConstant('{param:PUBLISHER|}') + '"';
end;
function PrepareToInstall(var NeedsRestart: Boolean): String;
var Code: Integer;
begin
  Result := '';
  ExtractTemporaryFile('payload.zip');
  Payload := ExpandConstant('{tmp}\payload\PHILCST-VMS');
  Code := RunPowerShell('-Command "Expand-Archive -LiteralPath ''' + ExpandConstant('{tmp}\payload.zip') + ''' -DestinationPath ''' + ExpandConstant('{tmp}\payload') + ''' -Force"');
  if Code <> 0 then begin Result := 'Payload extraction failed. No installation data changed.'; exit; end;
  Code := RunPowerShell('-File "' + Payload + '\app\deploy\windows\preflight.ps1" -Root "' + ExpandConstant('{app}') + '" -Payload "' + Payload + '"' + ModeArguments());
  Prepared := Code = 0;
  if not Prepared then Result := 'Preflight refused the package or installation. No application data changed. Run the extracted preflight.ps1 for details.';
end;
procedure CurStepChanged(CurStep: TSetupStep);
var Code: Integer;
begin
  if (CurStep = ssPostInstall) and Prepared then begin
    Code := RunPowerShell('-File "' + Payload + '\app\deploy\windows\install-package.ps1" -Root "' + ExpandConstant('{app}') + '" -Payload "' + Payload + '"' + ModeArguments());
    if Code <> 0 then RaiseException('Installation incomplete. Existing data retained; services may be stopped. See logs and rerun the same verified package. D5 rollback is not certified.');
  end;
end;
function InitializeUninstall(): Boolean;
begin
  Result := True;
  RemoveData := False;
  if UninstallSilent then RemoveData := CompareText(ExpandConstant('{param:REMOVEDATA|no}'), 'yes') = 0
  else RemoveData := MsgBox('Delete installation data as well? Choose No to retain database, accounts, media, keys and provisioning state.', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES;
end;
function RemoveDataSwitch(Param: String): String;
begin
  Result := '';
  if RemoveData then Result := '-RemoveData';
end;
