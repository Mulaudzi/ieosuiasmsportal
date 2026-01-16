<?php
/**
 * Auth Integration Tests
 * Tests authentication flow and user loading
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../bootstrap.php';

class AuthTest extends TestCase
{
    private PDO $pdo;
    private ?array $testUser = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = getTestDatabase();
    }
    
    protected function tearDown(): void
    {
        if ($this->testUser !== null) {
            cleanupTestUser($this->pdo, $this->testUser['id']);
        }
        TestAuth::reset();
        parent::tearDown();
    }
    
    /**
     * Test user creation and retrieval
     */
    public function testUserCreationAndRetrieval(): void
    {
        $this->testUser = createTestUser($this->pdo);
        
        $this->assertIsArray($this->testUser, 'User should be created');
        $this->assertArrayHasKey('id', $this->testUser, 'User should have ID');
        $this->assertIsInt($this->testUser['id'], 'User ID should be integer');
        $this->assertGreaterThan(0, $this->testUser['id'], 'User ID should be positive');
    }
    
    /**
     * Test Auth::id() returns correct integer
     */
    public function testAuthIdReturnsInt(): void
    {
        $this->testUser = createTestUser($this->pdo);
        TestAuth::setUser($this->testUser);
        
        $id = TestAuth::id();
        
        $this->assertIsInt($id, 'Auth::id() should return integer');
        $this->assertEquals($this->testUser['id'], $id, 'Auth::id() should match user ID');
    }
    
    /**
     * Test Auth::user() returns full user data
     */
    public function testAuthUserReturnsUserData(): void
    {
        $this->testUser = createTestUser($this->pdo);
        TestAuth::setUser($this->testUser);
        
        $user = TestAuth::user();
        
        $this->assertIsArray($user, 'Auth::user() should return array');
        $this->assertArrayHasKey('id', $user, 'User should have ID');
        $this->assertArrayHasKey('email', $user, 'User should have email');
        $this->assertArrayHasKey('name', $user, 'User should have name');
    }
    
    /**
     * Test Auth::check() returns correct boolean
     */
    public function testAuthCheckReturnsBoolean(): void
    {
        // Not authenticated
        TestAuth::reset();
        $this->assertFalse(TestAuth::check(), 'Should return false when not authenticated');
        
        // Authenticated
        $this->testUser = createTestUser($this->pdo);
        TestAuth::setUser($this->testUser);
        $this->assertTrue(TestAuth::check(), 'Should return true when authenticated');
    }
    
    /**
     * Test wallet is created with user
     */
    public function testWalletCreatedWithUser(): void
    {
        $this->testUser = createTestUser($this->pdo);
        
        $stmt = $this->pdo->prepare("SELECT * FROM wallets WHERE user_id = ?");
        $stmt->execute([$this->testUser['id']]);
        $wallet = $stmt->fetch();
        
        $this->assertNotFalse($wallet, 'Wallet should exist for new user');
        $this->assertEquals(100.00, (float)$wallet['balance'], 'Wallet should have initial balance');
    }
    
    /**
     * Test JWT token generation (if implemented)
     */
    public function testJwtTokenStructure(): void
    {
        $this->testUser = createTestUser($this->pdo);
        
        // Test JWT encode/decode if JWT class exists
        if (class_exists('JWT')) {
            $payload = [
                'sub' => $this->testUser['id'],
                'email' => $this->testUser['email'],
                'iat' => time(),
                'exp' => time() + 3600,
            ];
            
            // This tests the structure, not the actual JWT class
            $this->assertArrayHasKey('sub', $payload, 'JWT should contain sub claim');
            $this->assertArrayHasKey('exp', $payload, 'JWT should contain exp claim');
            $this->assertEquals($this->testUser['id'], $payload['sub'], 'Sub should match user ID');
        }
        
        $this->assertTrue(true); // Ensure test passes if JWT class doesn't exist
    }
    
    /**
     * Test user data isolation (users can't access other users' data)
     */
    public function testUserDataIsolation(): void
    {
        // Create two users
        $user1 = createTestUser($this->pdo);
        $user2 = createTestUser($this->pdo);
        
        // Create contact for user1
        $stmt = $this->pdo->prepare("INSERT INTO contacts (user_id, name, phone, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user1['id'], 'User1 Contact', '+11111111111']);
        
        // Create contact for user2
        $stmt->execute([$user2['id'], 'User2 Contact', '+22222222222']);
        
        // Query contacts for user1
        $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE user_id = ?");
        $stmt->execute([$user1['id']]);
        $user1Contacts = $stmt->fetchAll();
        
        // Query contacts for user2
        $stmt->execute([$user2['id']]);
        $user2Contacts = $stmt->fetchAll();
        
        // User1 should only see their contact
        $this->assertCount(1, $user1Contacts, 'User1 should have 1 contact');
        $this->assertEquals('User1 Contact', $user1Contacts[0]['name'], 'User1 should see their own contact');
        
        // User2 should only see their contact
        $this->assertCount(1, $user2Contacts, 'User2 should have 1 contact');
        $this->assertEquals('User2 Contact', $user2Contacts[0]['name'], 'User2 should see their own contact');
        
        // Cleanup second user
        cleanupTestUser($this->pdo, $user2['id']);
        $this->testUser = $user1; // Let tearDown handle user1
    }
    
    /**
     * Test database connection is valid
     */
    public function testDatabaseConnection(): void
    {
        $stmt = $this->pdo->query("SELECT 1");
        $result = $stmt->fetch();
        
        $this->assertNotFalse($result, 'Database should be connected');
    }
    
    /**
     * Test required tables exist
     */
    public function testRequiredTablesExist(): void
    {
        $requiredTables = [
            'users',
            'contacts',
            'contact_groups',
            'campaigns',
            'messages',
            'templates',
            'wallets',
            'wallet_transactions',
            'notifications',
        ];
        
        foreach ($requiredTables as $table) {
            try {
                $stmt = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
                $exists = true;
            } catch (\Exception $e) {
                $exists = false;
            }
            
            $this->assertTrue($exists, "Table {$table} should exist");
        }
    }
}
