param([string]$SettingsDirectory = (Join-Path $env:LOCALAPPDATA 'IEOSUIA Messaging\WhatsApp'))
$ErrorActionPreference = 'Stop'
# Local collection only. No network calls, active configuration changes or sends.
$sourceFile = Join-Path $SettingsDirectory 'meta-webhook.private.json'
$candidateFile = Join-Path $SettingsDirectory 'meta-production.private.json'
if (!(Test-Path -LiteralPath $sourceFile -PathType Leaf)) { throw 'The existing webhook settings were not found.' }
if (Test-Path -LiteralPath $candidateFile) { throw 'Production settings already exist. Nothing was overwritten.' }
if ((Get-Item -LiteralPath $SettingsDirectory).Attributes -band [IO.FileAttributes]::ReparsePoint) {
    throw 'Use the original private settings directory, not a directory link.'
}
$userSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$directoryAcl = Get-Acl -LiteralPath $SettingsDirectory
if (!$directoryAcl.AreAccessRulesProtected) { throw 'The private directory must have restricted, protected permissions.' }
foreach ($rule in $directoryAcl.Access) {
    $ruleSid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    if ($rule.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow -and
        $ruleSid -notin @($userSid.Value, 'S-1-5-18')) { throw 'The private directory has broader access than expected.' }
}
$sourceHash = (Get-FileHash -LiteralPath $sourceFile -Algorithm SHA256).Hash
$source = Get-Content -LiteralPath $sourceFile -Raw | ConvertFrom-Json
if ($source.schemaVersion -ne 1 -or $source.deployed -ne $true -or
    $source.wabaId -ne '997917760024995' -or $source.phoneNumberId -ne '1434779843044463' -or
    $source.callbackCandidate -ne 'https://sms.ieosuia.com/api/whatsapp-meta-webhook.php' -or
    $source.graphVersion -notmatch '^v[0-9]+\.0$' -or
    [string]::IsNullOrWhiteSpace($source.appSecretProtected) -or
    [string]::IsNullOrWhiteSpace($source.verifyTokenProtected)) {
    throw 'The source differs from the deployed test-sender settings. Inspect it before preparing a replacement.'
}
Write-Host 'IEOSUIA WhatsApp production preparation - local encrypted storage only'
Write-Host 'WhatsApp account: Ieosuia (1199128038269252)'
Write-Host 'Owner-provided Phone Number ID: 599942253205002'
Write-Host 'Paste the Meta ACCESS TOKEN authorized for this account. Do not enter the App Secret.'
Write-Host 'Nothing is uploaded, deployed, published, charged or sent.'
$token = Read-Host 'Meta access token' -AsSecureString
$tokenPointer = [IntPtr]::Zero
$stream = $null
$created = $false
$completed = $false
try {
    $tokenPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($token)
    $tokenText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer)
    # Format screening only; authorization and account membership still need API readback.
    if ($tokenText -cnotmatch '^[\x21-\x7e]{40,8192}$') {
        throw 'Expected an access token, without a Bearer prefix or surrounding whitespace. Nothing was saved.'
    }
    $tokenText = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)
    $tokenPointer = [IntPtr]::Zero
    $candidate = [ordered]@{
        schemaVersion = 1
        appId = '2108901959995210'
        wabaId = '1199128038269252'
        phoneNumberId = '599942253205002'
        graphVersion = [string]$source.graphVersion
        callbackCandidate = [string]$source.callbackCandidate
        appSecretProtected = [string]$source.appSecretProtected
        verifyTokenProtected = [string]$source.verifyTokenProtected
        accessTokenProtected = ConvertFrom-SecureString $token
        preparedAtUtc = [DateTime]::UtcNow.ToString('o')
        sourceConfigurationSha256 = $sourceHash.ToLowerInvariant()
        identifierEvidence = 'Owner-provided WhatsApp Manager IDs; API association not yet verified'
        tokenAuthorizationVerified = $false
        phoneAccountAssociationVerified = $false
        appSubscriptionVerified = $false
        deployed = $false
        sendEnabled = $false
    }
    if ((Get-FileHash -LiteralPath $sourceFile -Algorithm SHA256).Hash -ne $sourceHash) {
        throw 'The current webhook settings changed during preparation. Nothing was saved.'
    }
    $encoded = [Text.UTF8Encoding]::new($false).GetBytes(($candidate | ConvertTo-Json -Depth 3))
    $stream = [IO.File]::Open($candidateFile, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $created = $true
    $stream.Write($encoded, 0, $encoded.Length)
    $stream.Flush($true)
    $stream.Dispose()
    $stream = $null
    $completed = $true
} finally {
    if ($null -ne $stream) { $stream.Dispose() }
    if ($tokenPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer) }
    $token.Dispose()
    $tokenText = $null
    if ($created -and !$completed) { Remove-Item -LiteralPath $candidateFile -Force }
}
Write-Host ('Encrypted production candidate saved: ' + $candidateFile)
Write-Host 'The active test configuration is unchanged. Account permissions and phone association remain unverified.'
Write-Host 'Tell the assistant: Production WhatsApp settings saved. Do not paste the token into chat.'
