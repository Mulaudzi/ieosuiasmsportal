<?php
/**
 * Comprehensive API Test Suite
 * Tests all endpoints from APPLICATION_MAP.md
 * 
 * Usage: php api/tests/api_test_suite.php
 */

// Set up environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/up';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';

// Load required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/QueryBuilder.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/JWT.php';
require_once __DIR__ . '/../core/Auth.php';

// Test results storage
$testResults = [
    'passed' => 0,
    'failed' => 0,
    'errors' => [],
    'warnings' => [],
];

// Helper function to make API calls
function makeRequest($method, $endpoint, $data = null, $token = null) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/api' . $endpoint;
    
    // Set Authorization header
    if ($token) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    } else {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    
    // Set Content-Type
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    
    // Set input data
    if ($data !== null) {
        $GLOBALS['_POST'] = [];
        $GLOBALS['_GET'] = [];
        $GLOBALS['test_input'] = json_encode($data);
    }
    
    // Capture output
    ob_start();
    try {
        require __DIR__ . '/../index.php';
        $output = ob_get_clean();
        return json_decode($output, true);
    } catch (Exception $e) {
        ob_end_clean();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Test helper
function test($name, $callback) {
    global $testResults;
    echo "Testing: $name\n";
    try {
        $result = $callback();
        if ($result['success'] ?? false) {
            $testResults['passed']++;
            echo "  ✓ PASSED\n";
            return true;
        } else {
            $testResults['failed']++;
            $error = $result['message'] ?? $result['error'] ?? 'Unknown error';
            $testResults['errors'][] = "$name: $error";
            echo "  ✗ FAILED: $error\n";
            return false;
        }
    } catch (Exception $e) {
        $testResults['failed']++;
        $testResults['errors'][] = "$name: Exception - " . $e->getMessage();
        echo "  ✗ FAILED: Exception - " . $e->getMessage() . "\n";
        return false;
    }
}

// Create test user
function createTestUser() {
    $pdo = db();
    $email = 'test_' . time() . '@example.com';
    $password = password_hash('Test123456!', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, account_type, email_verified_at, created_at, updated_at)
        VALUES (?, ?, ?, 'standard', NOW(), NOW(), NOW())
    ");
    $stmt->execute(['Test User', $email]);
    $userId = $pdo->lastInsertId();
    
    // Create wallet
    $stmt = $pdo->prepare("
        INSERT INTO wallets (user_id, balance, reserved, currency, created_at, updated_at)
        VALUES (?, 1000, 0, 'ZAR', NOW(), NOW())
    ");
    $stmt->execute([$userId]);
    
    return ['id' => $userId, 'email' => $email, 'password' => 'Test123456!'];
}

// Cleanup test data
function cleanupTestUser($userId) {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM wallet_transactions WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = $userId)");
        $pdo->exec("DELETE FROM wallets WHERE user_id = $userId");
        $pdo->exec("DELETE FROM messages WHERE campaign_id IN (SELECT id FROM campaigns WHERE user_id = $userId)");
        $pdo->exec("DELETE FROM campaigns WHERE user_id = $userId");
        $pdo->exec("DELETE FROM contacts WHERE user_id = $userId");
        $pdo->exec("DELETE FROM templates WHERE user_id = $userId");
        $pdo->exec("DELETE FROM users WHERE id = $userId");
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
    }
}

echo "========================================\n";
echo "IEOSUIA SMS Portal - API Test Suite\n";
echo "========================================\n\n";

// Test 1: Health Check
test('Health Check (/up)', function() {
    return makeRequest('GET', '/up');
});

// Test 2: User Registration
$testUser = null;
test('User Registration', function() use (&$testUser) {
    $result = makeRequest('POST', '/auth/register', [
        'name' => 'Test User',
        'email' => 'test_' . time() . '@example.com',
        'password' => 'Test123456!',
        'password_confirmation' => 'Test123456!',
    ]);
    
    if ($result['success'] && isset($result['user'])) {
        $testUser = $result['user'];
        return $result;
    }
    return ['success' => false];
});

// Test 3: User Login
$authToken = null;
test('User Login', function() use (&$authToken, &$testUser) {
    if (!$testUser) {
        return ['success' => false, 'message' => 'No test user'];
    }
    
    // Get user from DB to get email
    $pdo = db();
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$testUser['id']]);
    $user = $stmt->fetch();
    
    $result = makeRequest('POST', '/auth/login', [
        'email' => $user['email'],
        'password' => 'Test123456!',
    ]);
    
    if ($result['success'] && isset($result['token'])) {
        $authToken = $result['token'];
        return $result;
    }
    return ['success' => false];
});

// Test 4: Get Current User
test('Get Current User', function() use ($authToken) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    return makeRequest('GET', '/auth/user', null, $authToken);
});

// Test 5: Create Contact
$contactId = null;
test('Create Contact', function() use ($authToken, &$contactId) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    
    $result = makeRequest('POST', '/contacts', [
        'name' => 'John Doe',
        'phone' => '+27123456789',
        'email' => 'john@example.com',
    ], $authToken);
    
    if ($result['success'] && isset($result['contact'])) {
        $contactId = $result['contact']['id'];
        return $result;
    }
    return ['success' => false];
});

// Test 6: Get Contacts
test('Get Contacts', function() use ($authToken) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    return makeRequest('GET', '/contacts', null, $authToken);
});

// Test 7: Update Contact
test('Update Contact', function() use ($authToken, $contactId) {
    if (!$authToken || !$contactId) {
        return ['success' => false, 'message' => 'Missing requirements'];
    }
    return makeRequest('PUT', "/contacts/$contactId", [
        'name' => 'John Updated',
    ], $authToken);
});

// Test 8: Create Template
$templateId = null;
test('Create Template', function() use ($authToken, &$templateId) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    
    $result = makeRequest('POST', '/templates', [
        'name' => 'Test SMS Template',
        'type' => 'sms',
        'content' => 'Hello {name}, this is a test message.',
    ], $authToken);
    
    if ($result['success'] && isset($result['template'])) {
        $templateId = $result['template']['id'];
        return $result;
    }
    return ['success' => false];
});

// Test 9: Get Templates
test('Get Templates', function() use ($authToken) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    return makeRequest('GET', '/templates', null, $authToken);
});

// Test 10: Create SMS Campaign
$campaignId = null;
test('Create SMS Campaign', function() use ($authToken, &$campaignId, $contactId) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    
    $result = makeRequest('POST', '/sms/campaigns', [
        'name' => 'Test SMS Campaign',
        'message' => 'Hello, this is a test SMS campaign.',
        'recipients' => ['+27123456789'],
        'sender_id' => 'TEST',
    ], $authToken);
    
    if ($result['success'] && isset($result['campaign'])) {
        $campaignId = $result['campaign']['id'];
        return $result;
    }
    return ['success' => false];
});

// Test 11: Get SMS Campaigns
test('Get SMS Campaigns', function() use ($authToken) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    return makeRequest('GET', '/sms/campaigns', null, $authToken);
});

// Test 12: Get Wallet Stats
test('Get Wallet Stats', function() use ($authToken) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    return makeRequest('GET', '/wallet/stats', null, $authToken);
});

// Test 13: Get Dashboard Stats
test('Get Dashboard Stats', function() use ($authToken) {
    if (!$authToken) {
        return ['success' => false, 'message' => 'No auth token'];
    }
    return makeRequest('GET', '/dashboard/stats', null, $authToken);
});

// Test 14: Delete Template
test('Delete Template', function() use ($authToken, $templateId) {
    if (!$authToken || !$templateId) {
        return ['success' => false, 'message' => 'Missing requirements'];
    }
    return makeRequest('DELETE', "/templates/$templateId", null, $authToken);
});

// Test 15: Delete Contact
test('Delete Contact', function() use ($authToken, $contactId) {
    if (!$authToken || !$contactId) {
        return ['success' => false, 'message' => 'Missing requirements'];
    }
    return makeRequest('DELETE', "/contacts/$contactId", null, $authToken);
});

// Cleanup
if ($testUser) {
    cleanupTestUser($testUser['id']);
}

// Print summary
echo "\n========================================\n";
echo "Test Summary\n";
echo "========================================\n";
echo "Passed: {$testResults['passed']}\n";
echo "Failed: {$testResults['failed']}\n";

if (!empty($testResults['errors'])) {
    echo "\nErrors:\n";
    foreach ($testResults['errors'] as $error) {
        echo "  - $error\n";
    }
}

echo "\n";
