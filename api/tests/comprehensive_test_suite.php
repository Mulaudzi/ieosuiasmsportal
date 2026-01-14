<?php
/**
 * Comprehensive API Test Suite
 * Tests all CRUD endpoints with sample data
 * 
 * Usage: php api/tests/comprehensive_test_suite.php [BASE_URL]
 * Example: php api/tests/comprehensive_test_suite.php http://localhost/api
 * 
 * Requirements:
 * - PHP 8.0+
 * - cURL extension
 * - Valid database connection configured in .env
 */

// Configuration
$BASE_URL = $argv[1] ?? 'http://localhost/api';
$VERBOSE = in_array('-v', $argv) || in_array('--verbose', $argv);

// Test results
$results = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
    'errors' => [],
];

// Colors for output
$GREEN = "\033[0;32m";
$RED = "\033[0;31m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$NC = "\033[0m"; // No Color

// Helper: Make HTTP request
function httpRequest($method, $url, $data = null, $token = null) {
    $ch = curl_init();
    
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'http_code' => 0];
    }
    
    return [
        'body' => json_decode($response, true),
        'http_code' => $httpCode,
        'raw' => $response,
    ];
}

// Test function
function test($name, $callback, $skip = false) {
    global $results, $GREEN, $RED, $YELLOW, $NC, $VERBOSE;
    
    echo str_pad($name, 60) . ' ';
    
    if ($skip) {
        echo $YELLOW . "SKIPPED" . $NC . "\n";
        $results['skipped']++;
        return null;
    }
    
    try {
        $result = $callback();
        
        if (isset($result['success']) && $result['success']) {
            echo $GREEN . "✓ PASSED" . $NC . "\n";
            $results['passed']++;
            if ($VERBOSE && isset($result['data'])) {
                echo "  Response: " . json_encode($result['data'], JSON_PRETTY_PRINT) . "\n";
            }
            return $result;
        } else {
            $error = $result['message'] ?? $result['error'] ?? 'Unknown error';
            echo $RED . "✗ FAILED" . $NC . "\n";
            echo "  Error: $error\n";
            $results['failed']++;
            $results['errors'][] = "$name: $error";
            return null;
        }
    } catch (Exception $e) {
        echo $RED . "✗ FAILED" . $NC . "\n";
        echo "  Exception: " . $e->getMessage() . "\n";
        $results['failed']++;
        $results['errors'][] = "$name: " . $e->getMessage();
        return null;
    }
}

echo "\n";
echo "========================================\n";
echo "IEOSUIA SMS Portal - Comprehensive Test Suite\n";
echo "========================================\n";
echo "Base URL: $BASE_URL\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

// Test data
$testUser = [
    'name' => 'Test User ' . time(),
    'email' => 'test_' . time() . '@example.com',
    'password' => 'Test123456!',
    'password_confirmation' => 'Test123456!',
];

$testContact = [
    'name' => 'John Doe',
    'phone' => '+27123456789',
    'email' => 'john@example.com',
];

$testTemplate = [
    'name' => 'Test SMS Template',
    'type' => 'sms',
    'content' => 'Hello {name}, this is a test message.',
];

$testSmsCampaign = [
    'name' => 'Test SMS Campaign',
    'message' => 'Hello, this is a test SMS campaign.',
    'recipients' => ['+27123456789'],
    'sender_id' => 'TEST',
];

$testEmailCampaign = [
    'name' => 'Test Email Campaign',
    'subject' => 'Test Subject',
    'message' => '<h1>Hello {name}</h1><p>This is a test email.</p>',
    'recipients' => ['test@example.com'],
];

// Variables to store IDs
$authToken = null;
$userId = null;
$contactId = null;
$templateId = null;
$smsCampaignId = null;
$emailCampaignId = null;
$groupId = null;

// ============================================
// AUTHENTICATION TESTS
// ============================================
echo $BLUE . "\n=== AUTHENTICATION ENDPOINTS ===\n" . $NC;

test('Health Check (/up)', function() use ($BASE_URL) {
    $result = httpRequest('GET', "$BASE_URL/up");
    return $result['http_code'] === 200 ? ['success' => true] : ['success' => false, 'error' => "HTTP {$result['http_code']}"];
});

$registerResult = test('User Registration', function() use ($BASE_URL, $testUser) {
    $result = httpRequest('POST', "$BASE_URL/auth/register", $testUser);
    if ($result['http_code'] === 201 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($registerResult) {
    $authToken = $registerResult['data']['token'] ?? null;
    $userId = $registerResult['data']['user']['id'] ?? null;
}

$loginResult = test('User Login', function() use ($BASE_URL, $testUser) {
    $result = httpRequest('POST', "$BASE_URL/auth/login", [
        'email' => $testUser['email'],
        'password' => $testUser['password'],
    ]);
    if ($result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($loginResult && !$authToken) {
    $authToken = $loginResult['data']['token'] ?? null;
}

if (!$authToken) {
    echo $RED . "\nERROR: Cannot proceed without authentication token!\n" . $NC;
    exit(1);
}

test('Get Current User', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/auth/user", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

test('Refresh Token', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('POST', "$BASE_URL/auth/refresh", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

// ============================================
// CONTACT TESTS
// ============================================
echo $BLUE . "\n=== CONTACT ENDPOINTS ===\n" . $NC;

$createContactResult = test('Create Contact', function() use ($BASE_URL, $authToken, $testContact) {
    $result = httpRequest('POST', "$BASE_URL/contacts", $testContact, $authToken);
    if ($result['http_code'] === 201 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($createContactResult) {
    $contactId = $createContactResult['data']['contact']['id'] ?? null;
}

test('Get Contacts List', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/contacts", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($contactId) {
    test('Get Single Contact', function() use ($BASE_URL, $authToken, $contactId) {
        $result = httpRequest('GET', "$BASE_URL/contacts/$contactId", null, $authToken);
        return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
    
    test('Update Contact', function() use ($BASE_URL, $authToken, $contactId) {
        $result = httpRequest('PUT', "$BASE_URL/contacts/$contactId", ['name' => 'John Updated'], $authToken);
        return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
}

test('Get Contact Groups', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/contact-groups", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

$createGroupResult = test('Create Contact Group', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('POST', "$BASE_URL/contact-groups", [
        'name' => 'Test Group',
        'description' => 'Test group description',
    ], $authToken);
    if ($result['http_code'] === 201 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($createGroupResult) {
    $groupId = $createGroupResult['data']['group']['id'] ?? null;
}

// ============================================
// TEMPLATE TESTS
// ============================================
echo $BLUE . "\n=== TEMPLATE ENDPOINTS ===\n" . $NC;

$createTemplateResult = test('Create Template', function() use ($BASE_URL, $authToken, $testTemplate) {
    $result = httpRequest('POST', "$BASE_URL/templates", $testTemplate, $authToken);
    if ($result['http_code'] === 201 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($createTemplateResult) {
    $templateId = $createTemplateResult['data']['template']['id'] ?? null;
}

test('Get Templates List', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/templates", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
});

if ($templateId) {
    test('Get Single Template', function() use ($BASE_URL, $authToken, $templateId) {
        $result = httpRequest('GET', "$BASE_URL/templates/$templateId", null, $authToken);
        return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
    
    test('Update Template', function() use ($BASE_URL, $authToken, $templateId) {
        $result = httpRequest('PUT', "$BASE_URL/templates/$templateId", [
            'name' => 'Updated Template Name',
            'content' => 'Updated content',
        ], $authToken);
        return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
}

// ============================================
// SMS CAMPAIGN TESTS
// ============================================
echo $BLUE . "\n=== SMS CAMPAIGN ENDPOINTS ===\n" . $NC;

$createSmsCampaignResult = test('Create SMS Campaign', function() use ($BASE_URL, $authToken, $testSmsCampaign) {
    $result = httpRequest('POST', "$BASE_URL/sms/campaigns", $testSmsCampaign, $authToken);
    if ($result['http_code'] === 201 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken); // Skip if no auth token

if ($createSmsCampaignResult) {
    $smsCampaignId = $createSmsCampaignResult['data']['campaign']['id'] ?? null;
}

test('Get SMS Campaigns List', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/sms/campaigns", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

if ($smsCampaignId) {
    test('Get Single SMS Campaign', function() use ($BASE_URL, $authToken, $smsCampaignId) {
        $result = httpRequest('GET', "$BASE_URL/sms/campaigns/$smsCampaignId", null, $authToken);
        return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
}

// ============================================
// EMAIL CAMPAIGN TESTS
// ============================================
echo $BLUE . "\n=== EMAIL CAMPAIGN ENDPOINTS ===\n" . $NC;

$createEmailCampaignResult = test('Create Email Campaign', function() use ($BASE_URL, $authToken, $testEmailCampaign) {
    $result = httpRequest('POST', "$BASE_URL/email/campaigns", $testEmailCampaign, $authToken);
    if ($result['http_code'] === 201 && isset($result['body']['success']) && $result['body']['success']) {
        return ['success' => true, 'data' => $result['body']];
    }
    return ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

if ($createEmailCampaignResult) {
    $emailCampaignId = $createEmailCampaignResult['data']['campaign']['id'] ?? null;
}

test('Get Email Campaigns List', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/email/campaigns", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

// ============================================
// WALLET TESTS
// ============================================
echo $BLUE . "\n=== WALLET ENDPOINTS ===\n" . $NC;

test('Get Wallet Balance', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/wallet", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

test('Get Wallet Stats', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/wallet/stats", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

test('Get Wallet Transactions', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/wallet/transactions", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

test('Get Credit Packages', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/wallet/packages", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

// ============================================
// DASHBOARD TESTS
// ============================================
echo $BLUE . "\n=== DASHBOARD ENDPOINTS ===\n" . $NC;

test('Get Dashboard Stats', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/dashboard/stats", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

test('Get Dashboard Chart', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/dashboard/chart", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

test('Get Recent Campaigns', function() use ($BASE_URL, $authToken) {
    $result = httpRequest('GET', "$BASE_URL/dashboard/recent-campaigns", null, $authToken);
    return $result['http_code'] === 200 && isset($result['body']['success']) && $result['body']['success']
        ? ['success' => true]
        : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
}, !$authToken);

// ============================================
// CLEANUP (DELETE TESTS)
// ============================================
echo $BLUE . "\n=== CLEANUP (DELETE OPERATIONS) ===\n" . $NC;

if ($templateId) {
    test('Delete Template', function() use ($BASE_URL, $authToken, $templateId) {
        $result = httpRequest('DELETE', "$BASE_URL/templates/$templateId", null, $authToken);
        return $result['http_code'] === 204 || ($result['http_code'] === 200 && isset($result['body']['success']))
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
}

if ($contactId) {
    test('Delete Contact', function() use ($BASE_URL, $authToken, $contactId) {
        $result = httpRequest('DELETE', "$BASE_URL/contacts/$contactId", null, $authToken);
        return $result['http_code'] === 204 || ($result['http_code'] === 200 && isset($result['body']['success']))
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
}

if ($groupId) {
    test('Delete Contact Group', function() use ($BASE_URL, $authToken, $groupId) {
        $result = httpRequest('DELETE', "$BASE_URL/contact-groups/$groupId", null, $authToken);
        return $result['http_code'] === 204 || ($result['http_code'] === 200 && isset($result['body']['success']))
            ? ['success' => true]
            : ['success' => false, 'error' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    });
}

// ============================================
// SUMMARY
// ============================================
echo "\n";
echo "========================================\n";
echo "TEST SUMMARY\n";
echo "========================================\n";
echo "Passed:  " . $GREEN . $results['passed'] . $NC . "\n";
echo "Failed:  " . ($results['failed'] > 0 ? $RED : '') . $results['failed'] . $NC . "\n";
echo "Skipped: " . $YELLOW . $results['skipped'] . $NC . "\n";
echo "\n";

if (!empty($results['errors'])) {
    echo "Errors:\n";
    foreach ($results['errors'] as $error) {
        echo "  - $error\n";
    }
    echo "\n";
}

echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "\n";

if ($results['failed'] === 0) {
    echo $GREEN . "✓ All tests passed!" . $NC . "\n";
    exit(0);
} else {
    echo $RED . "✗ Some tests failed!" . $NC . "\n";
    exit(1);
}
