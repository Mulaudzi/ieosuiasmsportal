param(
    [string]$DistPath,
    [Parameter(Mandatory=$true)][string]$SourceRoot,
    [switch]$ApiOnly,
    [switch]$ChangedOnly,
    [switch]$CleanObsolete,
    [switch]$IncludeEnv,
    [string[]]$ApiFiles=@()
)

$ErrorActionPreference='Stop'
$SourceRoot=[System.IO.Path]::GetFullPath($SourceRoot)
if(!(Test-Path -LiteralPath $SourceRoot -PathType Container)){throw 'SourceRoot must resolve to an existing directory'}
if(!$ApiOnly){
    $DistPath=[System.IO.Path]::GetFullPath($DistPath)
    if(!(Test-Path -LiteralPath $DistPath -PathType Container)){throw 'DistPath must resolve to an existing directory'}
    $sourcePrefix=$SourceRoot.TrimEnd('\')+'\'
    $distPrefix=$DistPath.TrimEnd('\')+'\'
    if(!$DistPath.StartsWith($sourcePrefix,[StringComparison]::OrdinalIgnoreCase)){throw 'DistPath must be inside SourceRoot'}
}
$accessPath=Join-Path $SourceRoot 'DEPLOYMENT_ACCESS.local.md'
$lines=Get-Content -LiteralPath $accessPath
function Field([string]$name){
    $line=$lines|Where-Object{$_ -match ('^\s*-\s*'+[regex]::Escape($name)+':\s*(.*)$')}|Select-Object -First 1
    if($line -match ':\s*(.*)$'){return $Matches[1].Trim()}
    return ''
}

$ftpHost=Field 'Server/host'
$ftpPort=[int](Field 'Port')
$credential=New-Object System.Net.NetworkCredential((Field 'Username'),(Field 'Password'))
$remoteRoot='sms'
$backupRoot=Join-Path $env:TEMP ('ieosuia-remote-backup-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backupRoot|Out-Null

function RemoteUri([string]$relative){
    $escaped=($relative.Trim('/') -split '/'|ForEach-Object{[uri]::EscapeDataString($_)}) -join '/'
    return "ftp://${ftpHost}:$ftpPort/$remoteRoot/$escaped"
}
function Request([string]$relative,[string]$method){
    $request=[System.Net.FtpWebRequest]::Create((RemoteUri $relative))
    $request.Method=$method;$request.Credentials=$credential;$request.UsePassive=$true
    $request.UseBinary=$true;$request.KeepAlive=$false;$request.Timeout=30000
    return $request
}
$listingCache=@{}
$ensuredDirectories=@{}
function DirectoryEntries([string]$relativeDirectory){
    $key=$relativeDirectory.Trim('/')
    if($listingCache.ContainsKey($key)){return @($listingCache[$key])}
    try{
        $request=Request $(if($key){$key}else{''}) ([System.Net.WebRequestMethods+Ftp]::ListDirectory)
        $response=$request.GetResponse();$reader=New-Object System.IO.StreamReader($response.GetResponseStream())
        $entries=@($reader.ReadToEnd() -split "`r?`n"|Where-Object{$_}|ForEach-Object{($_ -split '/')[-1]})
        $reader.Close();$response.Close();$listingCache[$key]=$entries;return $entries
    }catch{$listingCache[$key]=@();return @()}
}
function Exists([string]$relative){
    $normalized=$relative.Trim('/');$directory=[System.IO.Path]::GetDirectoryName($normalized).Replace('\','/')
    $name=[System.IO.Path]::GetFileName($normalized)
    return (DirectoryEntries $directory) -contains $name
}
function EnsureDirectory([string]$relativeDirectory){
    $current=''
    foreach($part in ($relativeDirectory.Trim('/') -split '/')){
        if(!$part){continue};$current=if($current){"$current/$part"}else{$part};if($ensuredDirectories.ContainsKey($current)){continue}
        try{$response=(Request $current ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)).GetResponse();$response.Close()}catch{}
        $ensuredDirectories[$current]=$true
    }
}
function BackupRemote([string]$relative){
    if(!(Exists $relative)){return}
    $destination=Join-Path $backupRoot ($relative -replace '/','\')
    New-Item -ItemType Directory -Path (Split-Path $destination -Parent) -Force|Out-Null
    $request=Request $relative ([System.Net.WebRequestMethods+Ftp]::DownloadFile)
    $response=$request.GetResponse();$input=$response.GetResponseStream();$output=[System.IO.File]::Create($destination)
    try{$input.CopyTo($output)}finally{$output.Close();$input.Close();$response.Close()}
}
function Upload([string]$local,[string]$relative){
    $directory=[System.IO.Path]::GetDirectoryName($relative).Replace('\','/')
    if($directory){EnsureDirectory $directory}
    BackupRemote $relative
    $bytes=[System.IO.File]::ReadAllBytes($local);$request=Request $relative ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $request.ContentLength=$bytes.Length;$stream=$request.GetRequestStream()
    try{$stream.Write($bytes,0,$bytes.Length)}finally{$stream.Close()}
    $response=$request.GetResponse();$response.Close()
    $listingCache.Remove($directory)
}
function RemoveRemote([string]$relative){
    if(!(Exists $relative)){return $false};BackupRemote $relative
    $response=(Request $relative ([System.Net.WebRequestMethods+Ftp]::DeleteFile)).GetResponse();$response.Close();return $true
}

$uploads=New-Object System.Collections.Generic.List[object]
if(!$ApiOnly){
    Get-ChildItem -LiteralPath $DistPath -Recurse -File|ForEach-Object{
        if(!$_.FullName.StartsWith($distPrefix,[StringComparison]::OrdinalIgnoreCase)){throw "Frontend file escaped DistPath: $($_.FullName)"}
        $relative=$_.FullName.Substring($distPrefix.Length).Replace('\','/')
        if(!$relative -or $relative.Contains('..')){throw "Unsafe frontend deployment path: $relative"}
        $uploads.Add([pscustomobject]@{Local=$_.FullName;Remote=$relative;Priority=if($relative -eq 'index.html'){30}elseif($relative.StartsWith('assets/')){10}else{20}})
    }
}
if(!$ChangedOnly){
    $apiPaths=@('bin','config','controllers','core','domain','lib','migrations','providers','services')
    foreach($apiPath in $apiPaths){
        $localRoot=Join-Path $SourceRoot "api/$apiPath";if(!(Test-Path $localRoot)){continue}
        Get-ChildItem -LiteralPath $localRoot -Recurse -File|Where-Object{
            $_.FullName -notmatch '[\\/]tests?[\\/]' -and $_.Name -ne '.env' -and
            (($_.Attributes -band [IO.FileAttributes]::Offline) -eq 0)
        }|ForEach-Object{
            $relative='api/'+$_.FullName.Substring((Join-Path $SourceRoot 'api').Length).TrimStart('\').Replace('\','/')
            $uploads.Add([pscustomobject]@{Local=$_.FullName;Remote=$relative;Priority=20})
        }
    }
}else{
    foreach($relativeFile in $ApiFiles){
        $normalized=$relativeFile.Replace('\','/').TrimStart('/')
        if(!$normalized.StartsWith('api/') -or $normalized -eq 'api/.env' -or $normalized.Contains('..')){throw "Unsafe API deployment path: $relativeFile"}
        $local=Join-Path $SourceRoot ($normalized.Replace('/','\'))
        if(!(Test-Path -LiteralPath $local -PathType Leaf)){throw "API deployment file not found: $normalized"}
        $uploads.Add([pscustomobject]@{Local=$local;Remote=$normalized;Priority=20})
    }
}
$uploads.Add([pscustomobject]@{Local=(Join-Path $SourceRoot 'api/index.php');Remote='api/index.php';Priority=30})
if($IncludeEnv){
    $environmentFile=Join-Path $SourceRoot 'api/.env'
    if(!(Test-Path -LiteralPath $environmentFile -PathType Leaf)){throw 'Local api/.env was not found'}
    $uploads.Add([pscustomobject]@{Local=$environmentFile;Remote='api/.env';Priority=40})
}

$uploaded=0
foreach($item in ($uploads|Sort-Object Priority,Remote)){Upload $item.Local $item.Remote;$uploaded++}
$removed=0
$obsolete=@(
    'api/controllers/TelnyxWebhookController.php',
    'api/services/TelnyxService.php',
    'api/controllers/GoogleAuthController.php',
    'api/services/GoogleOAuthService.php'
)
if($CleanObsolete){
    if($ApiOnly){throw '-CleanObsolete requires a frontend DistPath and cannot be combined with -ApiOnly'}
    $obsolete+=@(
        'api/controllers/E2ETestController.php','api/phpunit.xml','api/tests/bootstrap.php',
        'api/tests/Integration/AuthTest.php','api/tests/Unit/ContactControllerTest.php',
        'api/tests/Unit/TemplateControllerTest.php'
    )
    $currentAssets=@(Get-ChildItem -LiteralPath (Join-Path $DistPath 'assets') -File|ForEach-Object{$_.Name})
    foreach($asset in (DirectoryEntries 'assets')){
        if($asset -match '^index-.*\.(js|css)$' -and $currentAssets -notcontains $asset){$obsolete+="assets/$asset"}
    }
}
foreach($legacy in $obsolete|Select-Object -Unique){if(RemoveRemote $legacy){$removed++}}

Write-Output "UPLOAD_COMPLETE files=$uploaded removed_legacy=$removed"
Write-Output "BACKUP_ROOT=$backupRoot"
