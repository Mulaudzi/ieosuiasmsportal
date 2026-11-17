param([ValidateSet('api/tests/run-credit-transfers-mysql.php','api/tests/run-credit-transfer-setup-mysql.php')][string]$TestFile='api/tests/run-credit-transfers-mysql.php')
$ErrorActionPreference = 'Stop'
$fixturePort = 33160
if (Get-NetTCPConnection -LocalPort $fixturePort -State Listen -ErrorAction SilentlyContinue) { throw 'Fixture port already in use; not touching another server.' }
$fixturePath = Join-Path ([System.IO.Path]::GetTempPath()) ('sms-credit-transfers-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixturePath | Out-Null
& 'C:\xampp\mysql\bin\mysql_install_db.exe' ('--datadir=' + $fixturePath) | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Disposable database initialization failed.' }
$server = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList @('--no-defaults', ('--datadir="' + $fixturePath + '"'), '--port=33160', '--bind-address=127.0.0.1', '--skip-grant-tables', '--max-connections=30', '--innodb-use-native-aio=0', '--innodb-flush-method=normal', '--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru
try {
    $ready = $false
    for ($i=0; $i -lt 40; $i++) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33160 --user=root ping --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (!$ready) { throw 'Disposable fixture server unavailable.' }
    php $TestFile
    if ($LASTEXITCODE -ne 0) { throw 'Credit-transfer concurrency checks failed.' }
} finally {
    if (!$server.HasExited) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' --no-defaults --host=127.0.0.1 --port=33160 --user=root shutdown 2>$null
        if (!$server.WaitForExit(10000)) { throw 'Fixture shutdown needs inspection; no process was force-killed.' }
    }
    Write-Output ('Synthetic server logs retained at: ' + $fixturePath)
}
