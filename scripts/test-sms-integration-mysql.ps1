$ErrorActionPreference = 'Stop'
$integrationPort = 33169
if (Get-NetTCPConnection -LocalPort $integrationPort -State Listen -ErrorAction SilentlyContinue) { throw 'Fixture port is in use; no existing server will be touched.' }
$integrationData = Join-Path ([System.IO.Path]::GetTempPath()) ('sms-api-integration-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $integrationData | Out-Null
& 'C:\xampp\mysql\bin\mysql_install_db.exe' ('--datadir=' + $integrationData) | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Disposable database initialization failed.' }
$integrationServer = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList @('--no-defaults', ('--datadir="' + $integrationData + '"'), '--port=33169', '--bind-address=127.0.0.1', '--skip-grant-tables', '--max-connections=15', '--innodb-use-native-aio=0', '--innodb-flush-method=normal', '--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru
try {
    $integrationReady = $false
    for ($i=0; $i -lt 40; $i++) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33169 --user=root ping --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $integrationReady = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (!$integrationReady) { throw 'Disposable fixture did not become ready.' }
    & 'C:\php8\php.exe' (Join-Path $PSScriptRoot '../api/tests/run-sms-integration-mysql.php') --disposable-fixture
    if ($LASTEXITCODE -ne 0) { throw 'SMS API InnoDB tests failed.' }
    & 'C:\php8\php.exe' (Join-Path $PSScriptRoot '../api/tests/run-sms-integration-prerequisite.php') --disposable-fixture
    if ($LASTEXITCODE -ne 0) { throw 'SMS API integrity prerequisite tests failed.' }
} finally {
    if (!$integrationServer.HasExited) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33169 --user=root shutdown 2>$null
        if (!$integrationServer.WaitForExit(30000)) { throw 'Fixture needs shutdown inspection. No process was force-killed.' }
    }
    Write-Output ('Synthetic data/logs retained at: ' + $integrationData)
}
