param([switch]$ShowVerifyToken, [switch]$ReplaceAppSecret)
$ErrorActionPreference = 'Stop'
# No network, portal changes or sending. DPAPI protects secrets for this Windows user.
$settingsDirectory = Join-Path $env:LOCALAPPDATA 'IEOSUIA Messaging\WhatsApp'
$settingsFile = Join-Path $settingsDirectory 'meta-webhook.private.json'
$callbackCandidate = 'https://sms.ieosuia.com/api/whatsapp-meta-webhook.php'
$wabaId = '997917760024995'
$phoneId = '1434779843044463'
if ($ShowVerifyToken -and $ReplaceAppSecret) { throw 'Choose display or correction, not both.' }

if ($ShowVerifyToken) {
    if (!(Test-Path -LiteralPath $settingsFile -PathType Leaf)) { throw 'Run the setup first.' }
    $savedSettings = Get-Content -LiteralPath $settingsFile -Raw | ConvertFrom-Json
} else {
    $existingSettings = $null
    if (Test-Path -LiteralPath $settingsFile) {
        if (!$ReplaceAppSecret) { throw 'Settings already exist. Use -ShowVerifyToken to display the verification token, or the repair launcher to correct an undeployed App Secret.' }
        $existingSettings = Get-Content -LiteralPath $settingsFile -Raw | ConvertFrom-Json
        if ($existingSettings.deployed -ne $false -or $existingSettings.schemaVersion -ne 1 -or
            $existingSettings.wabaId -ne $wabaId -or $existingSettings.phoneNumberId -ne $phoneId -or
            $existingSettings.callbackCandidate -ne $callbackCandidate) { throw 'This correction is limited to the original undeployed test-sender configuration.' }
    } elseif ($ReplaceAppSecret) { throw 'There is no saved configuration to correct. Run the normal setup launcher first.' }
    Write-Host 'IEOSUIA WhatsApp webhook - local preparation'
    Write-Host 'Paste the Meta APP SECRET into the masked prompt below, not the access token.'
    Write-Host 'Nothing is uploaded, deployed or sent by this script.'
    $appSecret = Read-Host 'Meta App Secret' -AsSecureString
    $validationPointer = [IntPtr]::Zero
    try {
        $validationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($appSecret)
        $candidateSecret = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($validationPointer)
        $secretValid = $candidateSecret -cmatch '^[a-fA-F0-9]{32}$'
    } finally {
        if ($validationPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($validationPointer) }
        $candidateSecret = $null
    }
    if (!$secretValid) {
        $appSecret.Dispose()
        throw 'Expected the 32-character App Secret from App settings > Basic. An access token from WhatsApp API Setup cannot be used here. Nothing was saved or replaced.'
    }
    $verifySecret = $null
    if (!$existingSettings) {
        $tokenBytes = New-Object byte[] 32
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $rng.GetBytes($tokenBytes) } finally { $rng.Dispose() }
        $verifyText = [Convert]::ToBase64String($tokenBytes).TrimEnd('=').Replace('+','-').Replace('/','_')
        $verifySecret = ConvertTo-SecureString -String $verifyText -AsPlainText -Force
    }
    New-Item -ItemType Directory -Path $settingsDirectory -Force | Out-Null
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $userSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $inherit = [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    $propagate = [Security.AccessControl.PropagationFlags]::None
    $allow = [Security.AccessControl.AccessControlType]::Allow
    foreach ($sid in @($userSid, [Security.Principal.SecurityIdentifier]::new('S-1-5-18'))) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new($sid, [Security.AccessControl.FileSystemRights]::FullControl, $inherit, $propagate, $allow)
        $acl.AddAccessRule($rule)
    }
    if ($existingSettings) {
        $existingAcl = Get-Acl -LiteralPath $settingsDirectory
        if (!$existingAcl.AreAccessRulesProtected) { throw 'Private directory permissions have changed; inspect them before correction.' }
        $allowedSids = @($userSid.Value, 'S-1-5-18')
        foreach ($accessRule in $existingAcl.Access) {
            $accessSid = $accessRule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
            if ($accessRule.AccessControlType -eq $allow -and $accessSid -notin $allowedSids) { throw 'Private directory access has broadened; inspect it before correction.' }
        }
    } else {
        Set-Acl -LiteralPath $settingsDirectory -AclObject $acl
    }
    try {
        if ($existingSettings) {
            $savedSettings = $existingSettings
            $savedSettings.appSecretProtected = ConvertFrom-SecureString $appSecret
            $savedSettings | Add-Member -NotePropertyName secretCorrectedAtUtc -NotePropertyValue ([DateTime]::UtcNow.ToString('o')) -Force
            $correctionId = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffffffZ') + '-' + [Guid]::NewGuid().ToString('N')
            $pendingFile = Join-Path $settingsDirectory ('pending-' + $correctionId + '.json')
            $backupFile = Join-Path $settingsDirectory ('before-secret-correction-' + $correctionId + '.json')
            try {
                [IO.File]::WriteAllText($pendingFile, ($savedSettings | ConvertTo-Json -Depth 3), [Text.UTF8Encoding]::new($false))
                [IO.File]::Replace($pendingFile, $settingsFile, $backupFile, $true)
            } finally {
                if (Test-Path -LiteralPath $pendingFile) { Remove-Item -LiteralPath $pendingFile -Force }
            }
            Write-Host 'The previous encrypted settings were preserved in the same private folder.'
        } else {
            $savedSettings = [pscustomobject]@{
                schemaVersion = 1
                wabaId = $wabaId
                phoneNumberId = $phoneId
                graphVersion = 'v25.0'
                callbackCandidate = $callbackCandidate
                appSecretProtected = ConvertFrom-SecureString $appSecret
                verifyTokenProtected = ConvertFrom-SecureString $verifySecret
                preparedAtUtc = [DateTime]::UtcNow.ToString('o')
                deployed = $false
            }
            [IO.File]::WriteAllText($settingsFile, ($savedSettings | ConvertTo-Json -Depth 3), [Text.UTF8Encoding]::new($false))
        }
    } finally {
        $appSecret.Dispose(); if ($null -ne $verifySecret) { $verifySecret.Dispose() }; $verifyText = $null
    }
    Write-Host 'The App Secret and verification token are saved with Windows encryption outside OneDrive.'
}

$verificationSecret = ConvertTo-SecureString -String $savedSettings.verifyTokenProtected
$pointer = [IntPtr]::Zero
try {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($verificationSecret)
    $verificationText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    Write-Host ''
    Write-Host ('Callback URL: ' + $savedSettings.callbackCandidate)
    if ($savedSettings.deployed -eq $true) {
        Write-Host 'Callback deployment is recorded. Use this URL and token in Meta, then Verify and save.'
    } else {
        Write-Host 'This URL must be deployed and checked before Meta Verify and save.'
    }
    Write-Host ''
    Write-Host 'Verify token (copy into Meta after deployment):'
    Write-Host $verificationText
    Write-Host ''
    Write-Host ('Private configuration saved: ' + $settingsFile)
    Write-Host 'Tell the assistant: WhatsApp webhook settings saved. Do not paste secrets into chat.'
} finally {
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    $verificationSecret.Dispose(); $verificationText = $null
}
