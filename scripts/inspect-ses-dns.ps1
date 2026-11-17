$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$out=Join-Path $root '.sms-audit.local/amazon-ses-setup-20261029'
$access=Get-Content -LiteralPath (Join-Path $root 'DEPLOYMENT_ACCESS.local.md')
function Field([string]$name){
    $line=$access|Where-Object {$_ -match ('^\s*-\s*'+[regex]::Escape($name)+':\s*(.*)$')}|Select-Object -First 1
    if($line -match ':\s*(.*)$'){return $Matches[1].Trim()}
    throw 'Required private deployment field is absent'
}
$credentialText=(Field 'Username')+':'+(Field 'Password')
$auth=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($credentialText))
[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
$uri='https://cphost25.vpslocal.co.za:2083/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=ZoneEdit&cpanel_jsonapi_func=fetchzone&domain=ieosuia.com'
try {
    $response=Invoke-RestMethod -Uri $uri -Method Get -Headers @{Authorization=('Basic '+$auth)} -TimeoutSec 25
    $result=$response.cpanelresult
    $stamp=[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffffffZ')
    $utf8=New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText((Join-Path $out ("dns-inspection-$stamp.json")),($response|ConvertTo-Json -Depth 25),$utf8)
    $first=@($result.data)[0]
    [pscustomobject]@{authenticated=($result.event.result -eq 1);data_fields=@($first.PSObject.Properties.Name);read_only=$true;zone='ieosuia.com';dns_changes=$false}|ConvertTo-Json -Depth 5
} catch {
    $status=if($_.Exception.Response){[int]$_.Exception.Response.StatusCode}else{0}
    [pscustomobject]@{authenticated=$false;http_status=$status;error_class=$_.Exception.GetType().Name;zone='ieosuia.com';read_only=$true;dns_changes=$false}|ConvertTo-Json
    exit 1
}
