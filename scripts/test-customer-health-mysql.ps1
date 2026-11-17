$ErrorActionPreference = 'Stop'
$healthFixturePort = 33167
if (Get-NetTCPConnection -LocalPort $healthFixturePort -State Listen -ErrorAction SilentlyContinue) { throw 'Fixture port is in use; no existing server will be touched.' }
$healthFixturePath = Join-Path ([System.IO.Path]::GetTempPath()) ('sms-health-fixture-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $healthFixturePath | Out-Null
& 'C:\xampp\mysql\bin\mysql_install_db.exe' ('--datadir=' + $healthFixturePath) | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Disposable database initialization failed.' }
$healthServer = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList @('--no-defaults', ('--datadir="' + $healthFixturePath + '"'), '--port=33167', '--bind-address=127.0.0.1', '--skip-grant-tables', '--max-connections=15', '--innodb-use-native-aio=0', '--innodb-flush-method=normal', '--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru
try {
    $healthReady = $false
    for ($i=0; $i -lt 40; $i++) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33167 --user=root ping --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $healthReady = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (!$healthReady) { throw 'Disposable fixture did not become ready.' }
    & 'C:\php8\php.exe' (Join-Path $PSScriptRoot '../api/tests/run-customer-health-mysql.php') --disposable-fixture
    if ($LASTEXITCODE -ne 0) { throw 'Customer-health InnoDB tests failed.' }
} finally {
    if (!$healthServer.HasExited) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33167 --user=root shutdown 2>$null
        if (!$healthServer.WaitForExit(30000)) { throw 'Fixture needs shutdown inspection. No process was force-killed.' }
    }
    Write-Output ('Synthetic database logs retained at: ' + $healthFixturePath)
}
