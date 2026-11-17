param()
$ErrorActionPreference='Stop'
$emailFixtureBin=Join-Path $PSScriptRoot '../.sms-audit.local/email-aws-tools/mariadb-11.4.13-winx64/bin'
if(!(Test-Path -LiteralPath (Join-Path $emailFixtureBin 'mysqld.exe'))){throw 'Install the checksum-verified portable MariaDB fixture tools first; shared databases are never used.'}
$emailFixturePort=33169
if(Get-NetTCPConnection -LocalPort $emailFixturePort -State Listen -ErrorAction SilentlyContinue){throw 'Fixture port occupied; existing process is unchanged.'}
$emailFixtureDirectory=Join-Path ([IO.Path]::GetTempPath()) ('email-aws-mysql-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $emailFixtureDirectory | Out-Null
& (Join-Path $emailFixtureBin 'mysql_install_db.exe') ('--datadir='+$emailFixtureDirectory) | Out-Null
if($LASTEXITCODE -ne 0){throw 'Disposable database initialization failed.'}
$emailFixtureServer=Start-Process -FilePath (Join-Path $emailFixtureBin 'mysqld.exe') -ArgumentList @('--no-defaults',('--datadir="'+$emailFixtureDirectory+'"'),('--port='+$emailFixturePort),'--bind-address=127.0.0.1','--skip-grant-tables','--thread-handling=one-thread-per-connection','--max-connections=20','--innodb-use-native-aio=0','--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru
try{
 $emailFixtureReady=$false
 for($i=0;$i -lt 40;$i++){
  & (Join-Path $emailFixtureBin 'mysqladmin.exe') --no-defaults --host=127.0.0.1 --port=33169 --user=root ping --silent 2>$null | Out-Null
  if($LASTEXITCODE -eq 0){$emailFixtureReady=$true;break}
  Start-Sleep -Milliseconds 250
 }
 if(!$emailFixtureReady){throw 'Owned fixture server did not start.'}
 & 'C:\php8\php.exe' api/tests/run-email-aws-mysql.php
 if($LASTEXITCODE -ne 0){throw 'AWS email bridge MySQL acceptance failed.'}
 $emailFixtureSources=@('api/domain/EmailAwsAccounting.php','api/domain/EmailAwsPolicy.php','api/domain/EmailAwsSignature.php','api/migrations/029_email_aws_bridge.php','api/migrations/030_email_reply_to_account_binding.php','api/tests/run-email-aws-mysql.php','scripts/test-email-aws-mysql.ps1')
 $emailFixtureHashes=@{}
 foreach($emailFixtureSource in $emailFixtureSources){$emailFixtureHashes[$emailFixtureSource]=(Get-FileHash -LiteralPath $emailFixtureSource -Algorithm SHA256).Hash.ToLowerInvariant()}
 $emailFixtureReceipt=Join-Path (Get-Location) '.sms-audit.local/email-aws-release-20261112/mysql-acceptance.json'
 [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($emailFixtureReceipt)) | Out-Null
 [IO.File]::WriteAllText($emailFixtureReceipt,(@{passed=$true;source_sha256=$emailFixtureHashes;genuine_parallel_processes=$true;database='disposable-loopback-only';fixture_logs=$emailFixtureDirectory;at_utc=[DateTime]::UtcNow.ToString('o')}|ConvertTo-Json -Depth 5))
}finally{
 if(!$emailFixtureServer.HasExited){& (Join-Path $emailFixtureBin 'mysqladmin.exe') --no-defaults --host=127.0.0.1 --port=33169 --user=root shutdown 2>$null;if(!$emailFixtureServer.WaitForExit(10000)){throw 'Owned fixture process requires inspection.'}}
 Write-Output ('Synthetic database logs preserved at: '+$emailFixtureDirectory)
}
