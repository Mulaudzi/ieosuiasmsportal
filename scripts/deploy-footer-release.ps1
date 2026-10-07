$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$release=Join-Path $root '.footer-release.local/source/dist'
$built=Get-Content -Raw -LiteralPath (Join-Path $release 'index.html')
$asset=[regex]::Match($built,'src="/assets/(index-[^"]+\.js)"').Groups[1].Value
if(!$asset){throw 'Missing release asset'}
$lines=Get-Content -LiteralPath (Join-Path $root 'DEPLOYMENT_ACCESS.local.md')
function Field($name){$line=$lines|Where-Object{$_ -match ('^\s*-\s*'+[regex]::Escape($name)+':\s*(.*)$')}|Select-Object -First 1;if($line -match ':\s*(.*)$'){$Matches[1].Trim()}}
$credential=New-Object Net.NetworkCredential((Field 'Username'),(Field 'Password'))
function Request($relative,$method){$r=[Net.FtpWebRequest]::Create(('ftp://'+(Field 'Server/host')+':'+(Field 'Port')+'/sms/'+$relative));$r.Credentials=$credential;$r.Method=$method;$r.Timeout=30000;return $r}
function Download($relative){$r=Request $relative 'RETR';$response=$r.GetResponse();$memory=New-Object IO.MemoryStream;$response.GetResponseStream().CopyTo($memory);$response.Close();return ,$memory.ToArray()}
function Upload($relative,[byte[]]$bytes){$r=Request $relative 'STOR';$r.ContentLength=$bytes.Length;$s=$r.GetRequestStream();$s.Write($bytes,0,$bytes.Length);$s.Close();$r.GetResponse().Close()}
$original=Download 'index.html';$html=[Text.Encoding]::UTF8.GetString($original)
$old='index-DFaixxSR.js'
if(([regex]::Matches($html,[regex]::Escape($old))).Count -ne 1){throw 'Live release changed; refusing to overwrite'}
$backup=Join-Path $root ('.footer-release.local/live-backup-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backup | Out-Null
[IO.File]::WriteAllBytes((Join-Path $backup 'index.html'),$original)
[IO.File]::WriteAllBytes((Join-Path $backup $old),(Download ('assets/'+$old)))
Upload ('assets/'+$asset) ([IO.File]::ReadAllBytes((Join-Path $release ('assets/'+$asset))))
Upload 'index.html' ([Text.Encoding]::UTF8.GetBytes($html.Replace($old,$asset)))
Write-Output ('FOOTER_RELEASE_DEPLOYED '+$asset)
Write-Output ('BACKUP '+$backup)
