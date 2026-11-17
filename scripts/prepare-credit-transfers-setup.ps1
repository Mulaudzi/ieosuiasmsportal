param([string]$ReleaseDirectory='.sms-audit.local/credit-transfers-setup-20261030')
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$out=[IO.Path]::GetFullPath((Join-Path $root $ReleaseDirectory))
if(!$out.StartsWith((Join-Path $root '.sms-audit.local')+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Use the private audit folder'}
if(Test-Path (Join-Path $out 'manifest.json')){throw 'Inspect the existing preparation instead of replacing it'}
New-Item -ItemType Directory -Path $out -Force | Out-Null
function Hash([byte[]]$bytes){$h=[Security.Cryptography.SHA256]::Create();try{([BitConverter]::ToString($h.ComputeHash($bytes))).Replace('-','').ToLowerInvariant()}finally{$h.Dispose()}}
$tokenBytes=New-Object byte[] 32
$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($tokenBytes)}finally{$rng.Dispose()}
$key=[Convert]::ToBase64String($tokenBytes).TrimEnd('=').Replace('+','-').Replace('/','_')
$name='credit-transfers-setup-'+[guid]::NewGuid().ToString('N');$expires=[DateTimeOffset]::UtcNow.AddHours(24).ToUnixTimeSeconds()
$source=[IO.File]::ReadAllText((Join-Path $root 'api/domain/CreditTransferSetup.php'))
$source=[regex]::Replace($source,'\A<\?php\s*declare\(strict_types=1\);\s*','')
$template=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'credit-transfers-setup/runner.php.in'))
$candidate=$template.Replace('__EXPIRES__',[string]$expires).Replace('__KEY_HASH__',(Hash ([Text.Encoding]::UTF8.GetBytes($key)))).Replace('__CLIENT__',"$name.js").Replace('__STATE__',"$name.state.php").Replace('__SETUP_CLASS__',$source)
$utf8=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $out 'runner.php'),$candidate,$utf8)
[IO.File]::WriteAllText((Join-Path $out 'client.js'),[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'credit-transfers-setup/client.js')),$utf8)
$files=@()
foreach($path in @('api/migrations/015_credit_approval_security.php','api/migrations/021_credit_transfers.php')){$files+=@{remote=$path;local=$path;sha256=(Hash ([IO.File]::ReadAllBytes((Join-Path $root $path))));temporary=$false}}
$files+=@{remote="api/$name.js";local="$ReleaseDirectory/client.js";sha256=(Hash ([IO.File]::ReadAllBytes((Join-Path $out 'client.js'))));temporary=$true}
$files+=@{remote="api/$name.php";local="$ReleaseDirectory/runner.php";sha256=(Hash ([IO.File]::ReadAllBytes((Join-Path $out 'runner.php'))));temporary=$true}
$manifest=@{files=$files;state="api/config/$name.state.php";expires_at_utc=[DateTimeOffset]::FromUnixTimeSeconds($expires).ToString('o');key_sha256=(Hash ([Text.Encoding]::UTF8.GetBytes($key)));migration='021_credit_transfers';allowed_migrations=@('015_credit_approval_security','021_credit_transfers');email_migration_excluded=$true}
[IO.File]::WriteAllText((Join-Path $out 'manifest.json'),($manifest|ConvertTo-Json -Depth 8),$utf8)
[IO.File]::WriteAllText((Join-Path $out 'private-link.json'),(@{url="https://sms.ieosuia.com/api/$name.php#key=$key"}|ConvertTo-Json),$utf8)
& php -l (Join-Path $out 'runner.php');if($LASTEXITCODE -ne 0){throw 'Candidate PHP lint failed'}
Write-Output 'Prepared expiring, single-apply credit-transfer schema link. No network access or database changes.'
