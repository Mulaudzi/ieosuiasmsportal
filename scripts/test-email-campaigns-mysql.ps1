param()
$ErrorActionPreference='Stop'
$fixturePort=33161
if(Get-NetTCPConnection -LocalPort $fixturePort -State Listen -ErrorAction SilentlyContinue){throw 'Fixture port occupied; existing server is not touched.'}
$fixturePath=Join-Path ([IO.Path]::GetTempPath()) ('email-readiness-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixturePath | Out-Null
& 'C:\xampp\mysql\bin\mysql_install_db.exe' ('--datadir='+$fixturePath) | Out-Null
if($LASTEXITCODE -ne 0){throw 'Disposable database initialization failed.'}
$server=Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList @('--no-defaults',('--datadir="'+$fixturePath+'"'),'--port=33161','--bind-address=127.0.0.1','--skip-grant-tables','--max-connections=30','--innodb-use-native-aio=0','--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru
try{
 $ready=$false
 for($i=0;$i -lt 40;$i++){
  & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33161 --user=root ping --silent 2>$null | Out-Null
  if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 250
 }
 if(!$ready){throw 'Disposable server unavailable.'}
 php api/tests/run-email-campaigns-mysql.php
 if($LASTEXITCODE -ne 0){throw 'Email MySQL acceptance failed.'}
}finally{
 if(!$server.HasExited){& 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33161 --user=root shutdown 2>$null;if(!$server.WaitForExit(10000)){throw 'Owned fixture needs inspection; no force termination.'}}
 Write-Output ('Synthetic fixture logs retained at: '+$fixturePath)
}
