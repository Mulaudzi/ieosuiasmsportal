param([string]$ReleaseDirectory='.sms-audit.local/contact-tags-setup-20261029-v2')
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$out=[IO.Path]::GetFullPath((Join-Path $root $ReleaseDirectory))
if(!$out.StartsWith((Join-Path $root '.sms-audit.local')+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Use the private audit folder'}
if(Test-Path (Join-Path $out 'manifest.json')){throw 'Inspect the existing preparation instead of replacing it'}
New-Item -ItemType Directory -Path $out -Force | Out-Null
$tokenBytes=New-Object byte[] 32
$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($tokenBytes)}finally{$rng.Dispose()}
$key=[Convert]::ToBase64String($tokenBytes).TrimEnd('=').Replace('+','-').Replace('/','_')
$suffix=[guid]::NewGuid().ToString('N')
$name="contact-tags-setup-$suffix"
$hash={param([byte[]]$bytes) $algorithm=[Security.Cryptography.SHA256]::Create();try{([BitConverter]::ToString($algorithm.ComputeHash($bytes))).Replace('-','').ToLowerInvariant()}finally{$algorithm.Dispose()}}
$migration=Join-Path $root 'api/migrations/020_contact_tags.php'
$migrationHash=&$hash ([IO.File]::ReadAllBytes($migration))
$draftHash=&$hash ([IO.File]::ReadAllBytes((Join-Path $root 'api/migrations/016_work_drafts.php')))
$expires=[DateTimeOffset]::UtcNow.AddHours(24).ToUnixTimeSeconds()
$template=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'contact-tags-setup/runner.php.in'))
$candidate=$template.Replace('__EXPIRES__',[string]$expires).Replace('__KEY_HASH__',(&$hash ([Text.Encoding]::UTF8.GetBytes($key)))).Replace('__MIGRATION_HASH__',$migrationHash).Replace('__DRAFT_HASH__',$draftHash).Replace('__CLIENT__',"$name.js").Replace('__STATE__',"$name.state.php")
$utf8=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $out 'runner.php'),$candidate,$utf8)
[IO.File]::WriteAllText((Join-Path $out 'client.js'),[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'contact-tags-setup/client.js')),$utf8)
$files=@(
  @{remote='api/migrations/016_work_drafts.php';local='api/migrations/016_work_drafts.php';sha256=$draftHash;temporary=$false},
  @{remote='api/migrations/020_contact_tags.php';local='api/migrations/020_contact_tags.php';sha256=$migrationHash;temporary=$false},
  @{remote="api/$name.js";local="$ReleaseDirectory/client.js";sha256=(&$hash ([IO.File]::ReadAllBytes((Join-Path $out 'client.js'))));temporary=$true},
  @{remote="api/$name.php";local="$ReleaseDirectory/runner.php";sha256=(&$hash ([Text.Encoding]::UTF8.GetBytes($candidate)));temporary=$true}
)
$manifest=@{files=$files;state="api/config/$name.state.php";expires_at_utc=[DateTimeOffset]::FromUnixTimeSeconds($expires).ToString('o');key_sha256=(&$hash ([Text.Encoding]::UTF8.GetBytes($key)));migration='020_contact_tags';allowed_migrations=@('016_work_drafts','020_contact_tags');email_migration_excluded=$true}
[IO.File]::WriteAllText((Join-Path $out 'manifest.json'),($manifest|ConvertTo-Json -Depth 8),$utf8)
[IO.File]::WriteAllText((Join-Path $out 'private-link.json'),(@{url="https://sms.ieosuia.com/api/$name.php#key=$key"}|ConvertTo-Json),$utf8)
& php -l (Join-Path $out 'runner.php');if($LASTEXITCODE -ne 0){throw 'Candidate PHP lint failed'}
Write-Output 'Prepared expiring, single-apply contact-tags setup with its saved-draft prerequisite. No network access or database changes.'
