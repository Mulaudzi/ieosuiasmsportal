param(
  [ValidateSet('Publish','Inspect','Cleanup','CancelUnused')][string]$Mode='Inspect',
  [string]$ReleaseDirectory='.sms-audit.local/credit-transfers-setup-20261030'
)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$out=[IO.Path]::GetFullPath((Join-Path $root $ReleaseDirectory))
if(!$out.StartsWith((Join-Path $root '.sms-audit.local')+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Use the private audit folder'}
$manifest=Get-Content -LiteralPath (Join-Path $out 'manifest.json') -Raw | ConvertFrom-Json
if($manifest.migration -ne '021_credit_transfers' -or !$manifest.email_migration_excluded){throw 'Wrong migration manifest'}
$temporary=@($manifest.files|Where-Object {$_.temporary})
if($temporary.Count -ne 2 -or @($temporary|Where-Object {$_.remote -notmatch '^api/credit-transfers-setup-[a-f0-9]{32}\.(php|js)$'}).Count){throw 'Unexpected temporary targets'}
if($manifest.state -notmatch '^api/config/credit-transfers-setup-[a-f0-9]{32}\.state\.php$'){throw 'Unsafe receipt path'}
if($manifest.files.Count -ne 4){throw 'Expected exactly two migrations and two temporary files'}
if(@($manifest.files|Where-Object {!$_.temporary -and $_.remote -notin @('api/migrations/015_credit_approval_security.php','api/migrations/021_credit_transfers.php')}).Count){throw 'Unexpected permanent target'}
$utf8=New-Object Text.UTF8Encoding($false)
function Hash([byte[]]$bytes){$h=[Security.Cryptography.SHA256]::Create();try{([BitConverter]::ToString($h.ComputeHash($bytes))).Replace('-','').ToLowerInvariant()}finally{$h.Dispose()}}
function SaveRecord([string]$file,$data){[IO.File]::WriteAllText((Join-Path $out $file),($data|ConvertTo-Json -Depth 16),$utf8)}
$access=Get-Content -LiteralPath (Join-Path $root 'DEPLOYMENT_ACCESS.local.md')
function Field([string]$name){$line=$access|Where-Object {$_ -match ('^\s*-\s*'+[regex]::Escape($name)+':\s*(.*)$')}|Select-Object -First 1;if($line -match ':\s*(.*)$'){return $Matches[1].Trim()};throw 'Required deployment field missing'}
# The existing FTPS release transport verifies this hosting certificate name.
$ftpHost='cphost25.vpslocal.co.za';$ftpPort=[int](Field 'Port')
$credential=New-Object Net.NetworkCredential((Field 'Username'),(Field 'Password'))
[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
function FtpRequest([string]$relative,[string]$method){
  if($relative.Contains('..') -or $relative.StartsWith('/') -or $relative.Contains('\')){throw 'Unsafe remote path'}
  $escaped=($relative -split '/'|ForEach-Object {[uri]::EscapeDataString($_)}) -join '/'
  $request=[Net.FtpWebRequest]::Create("ftp://${ftpHost}:$ftpPort/sms/$escaped")
  $request.Credentials=$credential;$request.Method=$method;$request.EnableSsl=$true;$request.UsePassive=$true;$request.UseBinary=$true;$request.KeepAlive=$false;$request.Timeout=30000;$request.ReadWriteTimeout=30000
  return $request
}
function ReadRemote([string]$relative,[bool]$optional=$false){
  try{$response=(FtpRequest $relative ([Net.WebRequestMethods+Ftp]::DownloadFile)).GetResponse()}
  catch [Net.WebException]{if($optional -and $_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 550){$_.Exception.Response.Close();return $null};throw}
  $memory=New-Object IO.MemoryStream
  try{$stream=$response.GetResponseStream();try{$stream.CopyTo($memory)}finally{$stream.Close()};return ,$memory.ToArray()}finally{$memory.Dispose();$response.Close()}
}
function PutRemote([string]$relative,[byte[]]$bytes){
  $r=FtpRequest $relative ([Net.WebRequestMethods+Ftp]::UploadFile);$r.ContentLength=$bytes.Length;$s=$r.GetRequestStream();try{$s.Write($bytes,0,$bytes.Length)}finally{$s.Close()};$response=$r.GetResponse();$response.Close()
}
function DeleteExact([string]$relative,[string]$expected){
  $raw=ReadRemote $relative $true;if($null -eq $raw){return}
  if((Hash $raw) -ne $expected){throw "Refusing to delete changed file: $relative"}
  $r=(FtpRequest $relative ([Net.WebRequestMethods+Ftp]::DeleteFile)).GetResponse();$r.Close()
  if($null -ne (ReadRemote $relative $true)){throw "Remote deletion not verified: $relative"}
}
$guards=@('api/index.php','api/controllers/ContactController.php','api/controllers/WalletController.php','api/domain/CreditTransferService.php','api/controllers/CreditTransferController.php','api/config/database.php','api/bin/sms-worker.php','api/domain/WalletService.php','app.html','index.html','api/migrations/019_email_campaigns.php','api/bin/email-campaign-worker.php')
function GuardHashes {
  $result=@{};foreach($name in $guards){$raw=ReadRemote $name ($name -match 'email|CreditTransfer');$result[$name]=if($null -eq $raw){$null}else{Hash $raw}};return $result
}
function AssertGuards($expected){$current=GuardHashes;foreach($p in $guards){if($current[$p] -ne $expected[$p]){throw "Protected live file changed: $p"}}}
if($Mode -eq 'Publish'){
  if(Test-Path -LiteralPath (Join-Path $out 'publication.json')){throw 'Already published: inspect the existing receipt'}
  foreach($f in $manifest.files){if((Hash ([IO.File]::ReadAllBytes((Join-Path $root $f.local)))) -ne $f.sha256){throw 'Local candidate changed'}}
  $baseline=GuardHashes;SaveRecord 'baseline.json' @{files=$baseline;at_utc=[DateTime]::UtcNow.ToString('o');application_changes=$false}
  foreach($f in $manifest.files){$existing=ReadRemote $f.remote $true;if($null -ne $existing -and ($f.temporary -or (Hash $existing) -ne $f.sha256)){throw 'Remote target already exists with unexpected bytes'}}
  if($null -ne (ReadRemote $manifest.state $true)){throw 'Unexpected existing setup receipt'}
  $uploaded=@();$stages=@()
  try{
    foreach($f in $manifest.files){
      $existing=ReadRemote $f.remote $true;if($null -ne $existing){continue}
      $stage='api/config/credit-transfers-stage-'+[guid]::NewGuid().ToString('N')+'.php'
      if($null -ne (ReadRemote $stage $true)){throw 'Stage collision'}
      $bytes=[IO.File]::ReadAllBytes((Join-Path $root $f.local));$stages+=@{path=$stage;sha256=$f.sha256}
      PutRemote $stage $bytes;if((Hash (ReadRemote $stage)) -ne $f.sha256){throw 'Stage integrity mismatch'}
      if($null -ne (ReadRemote $f.remote $true)){throw 'Remote target appeared during publication'}
      $rename=FtpRequest $stage ([Net.WebRequestMethods+Ftp]::Rename);$rename.RenameTo='/sms/'+$f.remote;$response=$rename.GetResponse();$response.Close()
      if((Hash (ReadRemote $f.remote)) -ne $f.sha256){throw 'Published bytes mismatch'}
      $uploaded+=$f.remote;SaveRecord 'progress.json' @{uploaded=$uploaded;stages=$stages}
    }
    AssertGuards $baseline
    SaveRecord 'publication.json' @{uploaded=$uploaded;verified_files=$manifest.files;protected_files_unchanged=$guards.Count;email_changes=$false;application_ui_deployed=$false;database_migration_executed=$false;at_utc=[DateTime]::UtcNow.ToString('o')}
    Write-Output ('Published and byte-verified '+$manifest.files.Count+' scoped files. No database migration executed; email and portal UI unchanged.')
  }finally{
    foreach($s in $stages){DeleteExact $s.path $s.sha256}
  }
}elseif($Mode -eq 'Inspect'){
  $raw=ReadRemote $manifest.state $true
  if($null -ne $raw){$text=[Text.Encoding]::UTF8.GetString($raw);if(!$text.StartsWith("<?php exit; ?>`n")){throw 'Unexpected receipt format'};$receipt=$text.Substring("<?php exit; ?>`n".Length)|ConvertFrom-Json;SaveRecord 'runtime-receipt.json' $receipt;Write-Output ($receipt|ConvertTo-Json -Depth 12)}
  else {
    $private=Get-Content -LiteralPath (Join-Path $out 'private-link.json') -Raw|ConvertFrom-Json;$parts=$private.url -split '#key=',2
    try{$r=Invoke-WebRequest -Uri $parts[0] -UseBasicParsing -Headers @{'X-Credit-Transfer-Setup-Key'=$parts[1];Accept='application/json'} -TimeoutSec 40
      if($r.Headers['Content-Type'] -notmatch 'application/json'){SaveRecord 'http-check.json' @{status=[int]$r.StatusCode;json=$false;body_sha256=(Hash ([Text.Encoding]::UTF8.GetBytes($r.Content)))};Write-Output 'Hosting verification requires the browser; no migration was run.'}
      else {$data=$r.Content|ConvertFrom-Json;SaveRecord 'runtime-inspection.json' $data;Write-Output ($data|ConvertTo-Json -Depth 12)}
    }catch [Net.WebException]{SaveRecord 'http-check.json' @{status=if($_.Exception.Response){[int]$_.Exception.Response.StatusCode}else{0};browser_handoff_required=$true};Write-Output 'Hosting verification requires the browser; no migration was run.'}
  }
}elseif($Mode -eq 'CancelUnused'){
  if($null -ne (ReadRemote $manifest.state $true)){throw 'An apply attempt exists; inspect it rather than cancelling an unused link'}
  $before=GuardHashes
  foreach($f in $temporary){DeleteExact $f.remote $f.sha256}
  AssertGuards $before;SaveRecord 'cancel-unused.json' @{removed=@($temporary.remote);no_apply_attempt=$true;canonical_migration_retained=$true;protected_files_unchanged=$guards.Count;at_utc=[DateTime]::UtcNow.ToString('o')}
  Write-Output 'Removed the unused temporary link files after verifying no apply attempt exists. Canonical migration retained; no database changes.'
}else {
  $raw=ReadRemote $manifest.state
  $text=[Text.Encoding]::UTF8.GetString($raw);if(!$text.StartsWith("<?php exit; ?>`n")){throw 'Unexpected receipt format'}
  $receipt=$text.Substring("<?php exit; ?>`n".Length)|ConvertFrom-Json
  if($receipt.phase -ne 'completed' -or !$receipt.result.ok -or $receipt.result.migration -ne '021_credit_transfers'){throw 'Setup completion must be inspected before cleanup'}
  SaveRecord 'runtime-receipt.json' $receipt;$before=GuardHashes
  foreach($f in $manifest.files){if((Hash (ReadRemote $f.remote)) -ne $f.sha256){throw 'Published file drift: inspect before cleanup'}}
  foreach($f in $temporary){DeleteExact $f.remote $f.sha256}
  DeleteExact $manifest.state (Hash $raw);AssertGuards $before
  SaveRecord 'cleanup.json' @{removed=@($temporary.remote)+@($manifest.state);migration_file_retained=$true;migration='021_credit_transfers';receipt_archived=$true;protected_files_unchanged=$guards.Count;at_utc=[DateTime]::UtcNow.ToString('o')}
  Write-Output 'Deleted and verified both temporary web files and their private receipt. Additive credit-transfer schema and canonical migration retained. Completion receipt archived locally.'
}
