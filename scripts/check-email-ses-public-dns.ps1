param()
# Public DNS only. No cPanel authentication, zone edits, mail or AWS mutations.
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$allRows=@(Import-Csv -LiteralPath (Join-Path $root '.sms-audit.local/amazon-ses-setup-20261029/ses-dns-records.csv'))
# Preserve unrelated notes/rows in the owner's file; query only the six expected DNS record types.
$records=@($allRows|Where-Object {$_.type -in @('TXT','MX','CNAME')})
if($records.Count -ne 6){throw 'Expected exactly six public DNS records; source file is unchanged.'}
$result=@()
foreach($record in $records){
 if($record.type -notin @('TXT','MX','CNAME') -or $record.name -notmatch '(^|\.)campaigns\.ieosuia\.com$'){throw 'Unexpected public DNS target'}
 try{
  $answers=Resolve-DnsName -Name $record.name -Type $record.type -DnsOnly -QuickTimeout -ErrorAction Stop
  $values=@($answers | Where-Object {$_.Type -eq $record.type} | ForEach-Object {
   if($record.type -eq 'TXT'){$_.Strings -join ''}elseif($record.type -eq 'MX'){[string]$_.Preference+' '+$_.NameExchange.TrimEnd('.')}else{$_.NameHost.TrimEnd('.')}
  })
  $result+=@{name=$record.name;type=$record.type;matched=($values -contains $record.value.TrimEnd('.'));actual=$values}
 }catch{$result+=@{name=$record.name;type=$record.type;matched=$false;error_class=$_.Exception.GetType().Name}}
}
$data=@{passed=(@($result|Where-Object {!$_.matched}).Count -eq 0);checked=$result.Count;records=$result;unrelated_rows_ignored=($allRows.Count-$records.Count);source_csv_unchanged=$true;public_dns_only=$true;dns_changed=$false;emails_sent=$false;at_utc=[DateTime]::UtcNow.ToString('o')}
$out=Join-Path $root '.sms-audit.local/email-ses-readiness-20261030'
if(!(Test-Path -LiteralPath $out)){New-Item -ItemType Directory -Path $out | Out-Null}
[IO.File]::WriteAllText((Join-Path $out ('public-dns-'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')+'.json')),($data|ConvertTo-Json -Depth 8),(New-Object Text.UTF8Encoding($false)))
[pscustomobject]@{passed=$data.passed;records_checked=$result.Count;matching_records=@($result|Where-Object {$_.matched}).Count;dns_changed=$false;emails_sent=$false}|ConvertTo-Json
if(!$data.passed){exit 1}
