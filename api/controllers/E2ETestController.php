<?php
/**
 * E2E Test Controller - Comprehensive End-to-End Testing
 * Tests all modules as a real user from frontend to database and back
 */

require_once __DIR__ . '/../services/AuditLogService.php';

class E2ETestController
{
    /**
     * Run PHPUnit tests and return JSON results
     */
    public static function runPhpunit(): void
    {
        $user = Auth::user();
        if (!$user) {
            Response::error('Unauthorized', 401);
            return;
        }
        
        $output = [];
        $returnCode = 0;
        $phpunitPath = __DIR__ . '/../vendor/bin/phpunit';
        $configPath = __DIR__ . '/../phpunit.xml';
        
        // Check if PHPUnit is installed
        if (!file_exists($phpunitPath)) {
            Response::success([
                'status' => 'not_installed',
                'message' => 'PHPUnit not installed. Run: composer require --dev phpunit/phpunit ^10',
                'output' => [],
            ]);
            return;
        }
        
        // Run PHPUnit with testdox format
        $command = escapeshellcmd($phpunitPath) . ' --testdox -c ' . escapeshellarg($configPath) . ' 2>&1';
        exec($command, $output, $returnCode);
        
        Response::success([
            'status' => $returnCode === 0 ? 'passed' : 'failed',
            'return_code' => $returnCode,
            'output' => $output,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
{
    private static int $userId;
    private static array $testData = [];
    private static array $createdRecords = [];
    
    /**
     * Run all E2E tests for the authenticated user
     */
    public static function runTests(): void
    {
        $user = Auth::user();
        if (!$user) {
            Response::error('Unauthorized - Please log in', 401);
            exit;
        }
        
        self::$userId = (int)$user['id'];
        $data = Request::input();
        $modules = $data['modules'] ?? ['all'];
        
        $startTime = microtime(true);
        
        $results = [
            'meta' => [
                'user_id' => self::$userId,
                'user_email' => $user['email'],
                'started_at' => date('Y-m-d H:i:s'),
                'php_version' => PHP_VERSION,
            ],
            'modules' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
        ];
        
        // Define all testable modules
        $allModules = ['dashboard', 'contacts', 'contact_groups', 'templates', 'sms_campaigns', 'email_campaigns', 'wallet', 'reports', 'settings'];
        
        $modulesToTest = in_array('all', $modules) ? $allModules : $modules;
        
        foreach ($modulesToTest as $module) {
            $results['modules'][$module] = self::testModule($module);
        }
        
        // Calculate summary
        foreach ($results['modules'] as $moduleResults) {
            foreach ($moduleResults['tests'] as $test) {
                $results['summary']['total']++;
                if ($test['status'] === 'passed') {
                    $results['summary']['passed']++;
                } elseif ($test['status'] === 'failed') {
                    $results['summary']['failed']++;
                } else {
                    $results['summary']['skipped']++;
                }
            }
        }
        
        $results['meta']['completed_at'] = date('Y-m-d H:i:s');
        $results['meta']['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        
        // Cleanup test data
        self::cleanup();
        
        Response::success(['results' => $results]);
    }
    
    /**
     * Test a specific module
     */
    private static function testModule(string $module): array
    {
        $result = [
            'module' => $module,
            'tests' => [],
        ];
        
        switch ($module) {
            case 'dashboard':
                $result['tests'] = self::testDashboard();
                break;
            case 'contacts':
                $result['tests'] = self::testContacts();
                break;
            case 'contact_groups':
                $result['tests'] = self::testContactGroups();
                break;
            case 'templates':
                $result['tests'] = self::testTemplates();
                break;
            case 'sms_campaigns':
                $result['tests'] = self::testSmsCampaigns();
                break;
            case 'email_campaigns':
                $result['tests'] = self::testEmailCampaigns();
                break;
            case 'wallet':
                $result['tests'] = self::testWallet();
                break;
            case 'reports':
                $result['tests'] = self::testReports();
                break;
            case 'settings':
                $result['tests'] = self::testSettings();
                break;
        }
        
        return $result;
    }
    
    // ==================== MODULE TESTS ====================
    
    private static function testDashboard(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET dashboard stats
        $tests[] = self::executeTest(
            'GET Dashboard Stats',
            'dashboard',
            'GET',
            '/dashboard/stats',
            null,
            function() use ($pdo) {
                $query = "SELECT 
                    (SELECT COUNT(*) FROM campaigns WHERE user_id = :uid) as campaigns,
                    (SELECT COUNT(*) FROM contacts WHERE user_id = :uid2) as contacts
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute(['uid' => self::$userId, 'uid2' => self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: GET recent campaigns
        $tests[] = self::executeTest(
            'GET Recent Campaigns',
            'dashboard',
            'GET',
            '/dashboard/recent-campaigns',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                $stmt->execute([self::$userId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 3: GET chart data
        $tests[] = self::executeTest(
            'GET Dashboard Chart',
            'dashboard',
            'GET',
            '/dashboard/chart',
            null,
            null
        );
        
        return $tests;
    }
    
    private static function testContacts(): array
    {
        $tests = [];
        $pdo = db();
        $testPhone = '+1' . rand(2000000000, 9999999999);
        $testEmail = 'e2e_test_' . time() . '@test.com';
        
        // Test 1: GET contacts list
        $tests[] = self::executeTest(
            'GET Contacts List',
            'contacts',
            'GET',
            '/contacts',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM contacts WHERE user_id = ?");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: CREATE contact
        $createPayload = [
            'name' => 'E2E Test Contact ' . time(),
            'phone' => $testPhone,
            'email' => $testEmail,
        ];
        $createTest = self::executeTest(
            'CREATE Contact',
            'contacts',
            'POST',
            '/contacts',
            $createPayload,
            function() use ($pdo, $testPhone) {
                $stmt = $pdo->prepare("SELECT * FROM contacts WHERE phone = ? AND user_id = ?");
                $stmt->execute([$testPhone, self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        $tests[] = $createTest;
        
        // Get created contact ID for next tests
        $contactId = null;
        if ($createTest['status'] === 'passed' && isset($createTest['db_result']['id'])) {
            $contactId = $createTest['db_result']['id'];
            self::$createdRecords['contacts'][] = $contactId;
        }
        
        // Test 3: GET single contact
        if ($contactId) {
            $tests[] = self::executeTest(
                'GET Single Contact',
                'contacts',
                'GET',
                "/contacts/{$contactId}",
                null,
                function() use ($pdo, $contactId) {
                    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = ?");
                    $stmt->execute([$contactId]);
                    return $stmt->fetch(PDO::FETCH_ASSOC);
                }
            );
            
            // Test 4: UPDATE contact
            $updatePayload = ['name' => 'E2E Updated Contact ' . time()];
            $tests[] = self::executeTest(
                'UPDATE Contact',
                'contacts',
                'PUT',
                "/contacts/{$contactId}",
                $updatePayload,
                function() use ($pdo, $contactId, $updatePayload) {
                    $stmt = $pdo->prepare("SELECT name FROM contacts WHERE id = ?");
                    $stmt->execute([$contactId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result && strpos($result['name'], 'Updated') !== false;
                }
            );
            
            // Test 5: DELETE contact
            $tests[] = self::executeTest(
                'DELETE Contact',
                'contacts',
                'DELETE',
                "/contacts/{$contactId}",
                null,
                function() use ($pdo, $contactId) {
                    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = ?");
                    $stmt->execute([$contactId]);
                    return $stmt->fetch(PDO::FETCH_ASSOC) === false;
                }
            );
        } else {
            $tests[] = self::failTest('GET Single Contact', 'contacts', 'CREATE Contact failed - no contact ID available');
            $tests[] = self::failTest('UPDATE Contact', 'contacts', 'CREATE Contact failed - no contact ID available');
            $tests[] = self::failTest('DELETE Contact', 'contacts', 'CREATE Contact failed - no contact ID available');
        }
        
        // Test 6: EXPORT contacts (tests route ordering fix)
        $tests[] = self::executeTest(
            'EXPORT Contacts',
            'contacts',
            'GET',
            '/contacts/export',
            null,
            null // Export returns CSV, not JSON - just check it doesn't 404
        );
        
        return $tests;
    }
    
    private static function testContactGroups(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET contact groups
        $tests[] = self::executeTest(
            'GET Contact Groups',
            'contact_groups',
            'GET',
            '/contact-groups',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM contact_groups WHERE user_id = ?");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: CREATE contact group
        $groupName = 'E2E Test Group ' . time();
        $createPayload = [
            'name' => $groupName,
            'description' => 'Test group created by E2E tests',
        ];
        $createTest = self::executeTest(
            'CREATE Contact Group',
            'contact_groups',
            'POST',
            '/contact-groups',
            $createPayload,
            function() use ($pdo, $groupName) {
                $stmt = $pdo->prepare("SELECT * FROM contact_groups WHERE name = ? AND user_id = ?");
                $stmt->execute([$groupName, self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        $tests[] = $createTest;
        
        $groupId = null;
        if ($createTest['status'] === 'passed' && isset($createTest['db_result']['id'])) {
            $groupId = $createTest['db_result']['id'];
            self::$createdRecords['contact_groups'][] = $groupId;
        }
        
        // Test 3: UPDATE contact group
        if ($groupId) {
            $updatePayload = ['name' => 'E2E Updated Group ' . time()];
            $tests[] = self::executeTest(
                'UPDATE Contact Group',
                'contact_groups',
                'PUT',
                "/contact-groups/{$groupId}",
                $updatePayload,
                function() use ($pdo, $groupId) {
                    $stmt = $pdo->prepare("SELECT name FROM contact_groups WHERE id = ?");
                    $stmt->execute([$groupId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result && strpos($result['name'], 'Updated') !== false;
                }
            );
            
            // Test 4: DELETE contact group
            $tests[] = self::executeTest(
                'DELETE Contact Group',
                'contact_groups',
                'DELETE',
                "/contact-groups/{$groupId}",
                null,
                function() use ($pdo, $groupId) {
                    $stmt = $pdo->prepare("SELECT * FROM contact_groups WHERE id = ?");
                    $stmt->execute([$groupId]);
                    return $stmt->fetch(PDO::FETCH_ASSOC) === false;
                }
            );
        } else {
            $tests[] = self::failTest('UPDATE Contact Group', 'contact_groups', 'CREATE Contact Group failed - no group ID available');
            $tests[] = self::failTest('DELETE Contact Group', 'contact_groups', 'CREATE Contact Group failed - no group ID available');
        }
        
        return $tests;
    }
    
    private static function testTemplates(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET templates
        $tests[] = self::executeTest(
            'GET Templates',
            'templates',
            'GET',
            '/templates',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM templates WHERE user_id = ?");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: CREATE template
        $templateName = 'E2E Test Template ' . time();
        $createPayload = [
            'name' => $templateName,
            'content' => 'Hello {{name}}, this is a test message from E2E testing.',
            'type' => 'sms',
        ];
        $createTest = self::executeTest(
            'CREATE Template',
            'templates',
            'POST',
            '/templates',
            $createPayload,
            function() use ($pdo, $templateName) {
                $stmt = $pdo->prepare("SELECT * FROM templates WHERE name = ? AND user_id = ?");
                $stmt->execute([$templateName, self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        $tests[] = $createTest;
        
        $templateId = null;
        if ($createTest['status'] === 'passed' && isset($createTest['db_result']['id'])) {
            $templateId = $createTest['db_result']['id'];
            self::$createdRecords['templates'][] = $templateId;
        }
        
        // Test 3: GET single template
        if ($templateId) {
            $tests[] = self::executeTest(
                'GET Single Template',
                'templates',
                'GET',
                "/templates/{$templateId}",
                null,
                null
            );
            
            // Test 4: UPDATE template
            $updatePayload = [
                'name' => 'E2E Updated Template ' . time(),
                'content' => 'Updated content: Hello {{name}}!',
            ];
            $tests[] = self::executeTest(
                'UPDATE Template',
                'templates',
                'PUT',
                "/templates/{$templateId}",
                $updatePayload,
                function() use ($pdo, $templateId) {
                    $stmt = $pdo->prepare("SELECT name FROM templates WHERE id = ?");
                    $stmt->execute([$templateId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result && strpos($result['name'], 'Updated') !== false;
                }
            );
            
            // Test 5: DELETE template
            $tests[] = self::executeTest(
                'DELETE Template',
                'templates',
                'DELETE',
                "/templates/{$templateId}",
                null,
                function() use ($pdo, $templateId) {
                    $stmt = $pdo->prepare("SELECT * FROM templates WHERE id = ?");
                    $stmt->execute([$templateId]);
                    return $stmt->fetch(PDO::FETCH_ASSOC) === false;
                }
            );
        } else {
            $tests[] = self::failTest('GET Single Template', 'templates', 'CREATE Template failed - no template ID available');
            $tests[] = self::failTest('UPDATE Template', 'templates', 'CREATE Template failed - no template ID available');
            $tests[] = self::failTest('DELETE Template', 'templates', 'CREATE Template failed - no template ID available');
        }
        
        return $tests;
    }
    
    private static function testSmsCampaigns(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET SMS campaigns
        $tests[] = self::executeTest(
            'GET SMS Campaigns',
            'sms_campaigns',
            'GET',
            '/sms/campaigns',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM campaigns WHERE user_id = ? AND type = 'sms'");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: CREATE SMS campaign (draft only - don't actually send)
        $campaignName = 'E2E Test SMS Campaign ' . time();
        $createPayload = [
            'name' => $campaignName,
            'message' => 'This is an E2E test SMS message.',
            'recipients' => ['+10000000000'], // Invalid number to prevent actual send
            'status' => 'draft',
        ];
        $createTest = self::executeTest(
            'CREATE SMS Campaign (Draft)',
            'sms_campaigns',
            'POST',
            '/sms/campaigns',
            $createPayload,
            function() use ($pdo, $campaignName) {
                $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE name = ? AND user_id = ? AND type = 'sms'");
                $stmt->execute([$campaignName, self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        $tests[] = $createTest;
        
        $campaignId = null;
        if ($createTest['status'] === 'passed' && isset($createTest['db_result']['id'])) {
            $campaignId = $createTest['db_result']['id'];
            self::$createdRecords['campaigns'][] = $campaignId;
        }
        
        // Test 3: GET single campaign
        if ($campaignId) {
            $tests[] = self::executeTest(
                'GET Single SMS Campaign',
                'sms_campaigns',
                'GET',
                "/sms/campaigns/{$campaignId}",
                null,
                null
            );
            
            // Test 4: DELETE campaign
            $tests[] = self::executeTest(
                'DELETE SMS Campaign',
                'sms_campaigns',
                'DELETE',
                "/sms/campaigns/{$campaignId}",
                null,
                function() use ($pdo, $campaignId) {
                    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
                    $stmt->execute([$campaignId]);
                    return $stmt->fetch(PDO::FETCH_ASSOC) === false;
                }
            );
        } else {
            $tests[] = self::failTest('GET Single SMS Campaign', 'sms_campaigns', 'CREATE SMS Campaign failed - no campaign ID available');
            $tests[] = self::failTest('DELETE SMS Campaign', 'sms_campaigns', 'CREATE SMS Campaign failed - no campaign ID available');
        }
        
        return $tests;
    }
    
    private static function testEmailCampaigns(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET Email campaigns
        $tests[] = self::executeTest(
            'GET Email Campaigns',
            'email_campaigns',
            'GET',
            '/email/campaigns',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM campaigns WHERE user_id = ? AND type = 'email'");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: CREATE Email campaign (draft only)
        $campaignName = 'E2E Test Email Campaign ' . time();
        $createPayload = [
            'name' => $campaignName,
            'subject' => 'E2E Test Email Subject',
            'content' => '<p>This is an E2E test email message.</p>',
            'recipients' => ['test@invalid.test'], // Invalid to prevent send
            'status' => 'draft',
        ];
        $createTest = self::executeTest(
            'CREATE Email Campaign (Draft)',
            'email_campaigns',
            'POST',
            '/email/campaigns',
            $createPayload,
            function() use ($pdo, $campaignName) {
                $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE name = ? AND user_id = ? AND type = 'email'");
                $stmt->execute([$campaignName, self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        $tests[] = $createTest;
        
        $campaignId = null;
        if ($createTest['status'] === 'passed' && isset($createTest['db_result']['id'])) {
            $campaignId = $createTest['db_result']['id'];
            self::$createdRecords['campaigns'][] = $campaignId;
        }
        
        // Test 3: GET single campaign
        if ($campaignId) {
            $tests[] = self::executeTest(
                'GET Single Email Campaign',
                'email_campaigns',
                'GET',
                "/email/campaigns/{$campaignId}",
                null,
                null
            );
            
            // Test 4: DELETE campaign
            $tests[] = self::executeTest(
                'DELETE Email Campaign',
                'email_campaigns',
                'DELETE',
                "/email/campaigns/{$campaignId}",
                null,
                function() use ($pdo, $campaignId) {
                    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
                    $stmt->execute([$campaignId]);
                    return $stmt->fetch(PDO::FETCH_ASSOC) === false;
                }
            );
        } else {
            $tests[] = self::failTest('GET Single Email Campaign', 'email_campaigns', 'CREATE Email Campaign failed - no campaign ID available');
            $tests[] = self::failTest('DELETE Email Campaign', 'email_campaigns', 'CREATE Email Campaign failed - no campaign ID available');
        }
        
        return $tests;
    }
    
    private static function testWallet(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET wallet
        $tests[] = self::executeTest(
            'GET Wallet Balance',
            'wallet',
            'GET',
            '/wallet',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = ?");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: GET wallet stats
        $tests[] = self::executeTest(
            'GET Wallet Stats',
            'wallet',
            'GET',
            '/wallet/stats',
            null,
            null
        );
        
        // Test 3: GET transactions
        $tests[] = self::executeTest(
            'GET Wallet Transactions',
            'wallet',
            'GET',
            '/wallet/transactions',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM wallet_transactions WHERE user_id = ?");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 4: GET packages
        $tests[] = self::executeTest(
            'GET Credit Packages',
            'wallet',
            'GET',
            '/wallet/packages',
            null,
            null
        );
        
        return $tests;
    }
    
    private static function testReports(): array
    {
        $tests = [];
        
        // Test 1: GET report stats
        $tests[] = self::executeTest(
            'GET Report Stats',
            'reports',
            'GET',
            '/reports/stats',
            null,
            null
        );
        
        // Test 2: GET report chart
        $tests[] = self::executeTest(
            'GET Report Chart Data',
            'reports',
            'GET',
            '/reports/chart',
            null,
            null
        );
        
        // Test 3: GET delivery breakdown
        $tests[] = self::executeTest(
            'GET Delivery Breakdown',
            'reports',
            'GET',
            '/reports/delivery',
            null,
            null
        );
        
        // Test 4: GET campaigns report
        $tests[] = self::executeTest(
            'GET Campaigns Report',
            'reports',
            'GET',
            '/reports/campaigns',
            null,
            null
        );
        
        return $tests;
    }
    
    private static function testSettings(): array
    {
        $tests = [];
        $pdo = db();
        
        // Test 1: GET profile
        $tests[] = self::executeTest(
            'GET User Profile',
            'settings',
            'GET',
            '/settings/profile',
            null,
            function() use ($pdo) {
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
                $stmt->execute([self::$userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
        );
        
        // Test 2: UPDATE profile (only name to avoid breaking things)
        $currentUser = Auth::user();
        $originalName = $currentUser['name'];
        $testName = $originalName . ' (E2E Test)';
        
        $updateTest = self::executeTest(
            'UPDATE User Profile',
            'settings',
            'PUT',
            '/settings/profile',
            ['name' => $testName],
            function() use ($pdo, $testName) {
                $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $stmt->execute([self::$userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result && $result['name'] === $testName;
            }
        );
        $tests[] = $updateTest;
        
        // Restore original name
        if ($updateTest['status'] === 'passed') {
            $stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmt->execute([$originalName, self::$userId]);
        }
        
        return $tests;
    }
    
    // ==================== TEST EXECUTION HELPERS ====================
    
    private static function executeTest(
        string $name,
        string $module,
        string $method,
        string $endpoint,
        ?array $payload,
        ?callable $dbVerification
    ): array {
        $startTime = microtime(true);
        
        $result = [
            'name' => $name,
            'module' => $module,
            'method' => $method,
            'endpoint' => $endpoint,
            'payload' => $payload,
            'status' => 'pending',
            'response_status' => null,
            'response_body' => null,
            'db_query' => null,
            'db_result' => null,
            'error' => null,
            'stack_trace' => null,
            'duration_ms' => 0,
        ];
        
        try {
            // Build the full URL
            $baseUrl = 'https://sms.ieosuia.com/api';
            $url = $baseUrl . $endpoint;
            
            // Get auth token
            $token = null;
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
            }
            
            // Make HTTP request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ]);
            
            if ($payload && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
            
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            $result['response_status'] = $httpCode;
            
            if ($curlError) {
                throw new Exception("cURL error: " . $curlError);
            }
            
            // Parse response - handle both JSON and non-JSON (like CSV exports)
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $isJson = $contentType && strpos($contentType, 'application/json') !== false;
            $isCsv = $contentType && strpos($contentType, 'text/csv') !== false;
            
            if ($isJson || (!$isCsv && $response)) {
                $responseData = json_decode($response, true);
                $result['response_body'] = $responseData;
            } else {
                // For CSV or other non-JSON responses, just store raw
                $result['response_body'] = ['raw_response' => substr($response, 0, 500), 'content_type' => $contentType];
            }
            
            // Check if request was successful
            $isSuccess = $httpCode >= 200 && $httpCode < 300;
            
            if (!$isSuccess) {
                $errorMsg = 'Unknown error';
                if (is_array($responseData)) {
                    $errorMsg = $responseData['error'] ?? $responseData['message'] ?? 'Unknown error';
                } elseif ($response) {
                    $errorMsg = substr($response, 0, 200);
                }
                throw new Exception("HTTP {$httpCode}: " . $errorMsg);
            }
            
            // Run database verification if provided
            if ($dbVerification) {
                $dbResult = $dbVerification();
                $result['db_result'] = $dbResult;
                
                // For boolean checks
                if (is_bool($dbResult) && !$dbResult) {
                    throw new Exception("Database verification failed - data not found or mismatch");
                }
                
                // For array checks (should have data)
                if (is_array($dbResult) && empty($dbResult) && $method === 'POST') {
                    throw new Exception("Database verification failed - record not created");
                }
            }
            
            $result['status'] = 'passed';
            
        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            $result['stack_trace'] = $e->getTraceAsString();
        }
        
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        
        return $result;
    }
    
    private static function failTest(string $name, string $module, string $reason): array
    {
        return [
            'name' => $name,
            'module' => $module,
            'method' => 'N/A',
            'endpoint' => 'N/A',
            'payload' => null,
            'status' => 'failed',
            'response_status' => null,
            'response_body' => null,
            'db_query' => null,
            'db_result' => null,
            'error' => 'DEPENDENCY FAILED: ' . $reason,
            'stack_trace' => null,
            'duration_ms' => 0,
        ];
    }
    
    private static function cleanup(): void
    {
        $pdo = db();
        
        // Clean up any remaining test records
        foreach (self::$createdRecords as $table => $ids) {
            if (empty($ids)) continue;
            
            $realTable = $table;
            if ($table === 'contact_groups') $realTable = 'contact_groups';
            
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM {$realTable} WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }
    
    /**
     * Get quick health check
     */
    public static function healthCheck(): void
    {
        $user = Auth::user();
        if (!$user) {
            Response::error('Unauthorized', 401);
            exit;
        }
        
        $pdo = db();
        $health = [
            'database' => false,
            'tables' => [],
            'user_data' => [],
        ];
        
        try {
            // Check database connection
            $stmt = $pdo->query("SELECT 1");
            $health['database'] = true;
            
            // Check critical tables
            $tables = ['users', 'campaigns', 'contacts', 'contact_groups', 'templates', 'wallets', 'wallet_transactions', 'messages'];
            foreach ($tables as $table) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM {$table}");
                    $result = $stmt->fetch();
                    $health['tables'][$table] = [
                        'exists' => true,
                        'records' => (int)$result['cnt'],
                    ];
                } catch (Exception $e) {
                    $health['tables'][$table] = [
                        'exists' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            // User-specific data
            $userId = $user['id'];
            $health['user_data'] = [
                'contacts' => self::countUserRecords($pdo, 'contacts', $userId),
                'campaigns' => self::countUserRecords($pdo, 'campaigns', $userId),
                'templates' => self::countUserRecords($pdo, 'templates', $userId),
            ];
            
        } catch (Exception $e) {
            $health['error'] = $e->getMessage();
        }
        
        Response::success(['health' => $health]);
    }
    
    private static function countUserRecords(PDO $pdo, string $table, int $userId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM {$table} WHERE user_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return (int)$result['cnt'];
        } catch (Exception $e) {
            return -1;
        }
    }
}
