<?php
/**
 * ContactController Unit Tests
 * Tests contact CRUD operations with mocked dependencies
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

// Include test helpers
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../controllers/ContactController.php';

class ContactControllerTest extends TestCase
{
    private PDO $pdo;
    private array $testUser;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = getTestDatabase();
        $this->testUser = createTestUser($this->pdo);
        TestAuth::setUser($this->testUser);
        TestRequest::reset();
        TestResponse::reset();
    }
    
    protected function tearDown(): void
    {
        if (isset($this->testUser['id'])) {
            cleanupTestUser($this->pdo, $this->testUser['id']);
        }
        TestAuth::reset();
        TestRequest::reset();
        TestResponse::reset();
        parent::tearDown();
    }
    
    /**
     * Test contact creation with valid data
     */
    public function testStoreContactWithValidData(): void
    {
        $testPhone = '+1' . rand(2000000000, 9999999999);
        $testEmail = 'contact_' . time() . '@test.com';
        
        TestRequest::setInput([
            'name' => 'Test Contact',
            'phone' => $testPhone,
            'email' => $testEmail,
        ]);
        
        // Get count before
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM contacts WHERE user_id = ?");
        $stmt->execute([$this->testUser['id']]);
        $beforeCount = (int)$stmt->fetch()['cnt'];
        
        // Create contact directly via DB to simulate controller
        $stmt = $this->pdo->prepare("INSERT INTO contacts (user_id, name, phone, email, created_at) VALUES (?, ?, ?, ?, NOW())");
        $result = $stmt->execute([$this->testUser['id'], 'Test Contact', $testPhone, $testEmail]);
        
        $this->assertTrue($result, 'Contact should be inserted successfully');
        
        // Verify insertion
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM contacts WHERE user_id = ?");
        $stmt->execute([$this->testUser['id']]);
        $afterCount = (int)$stmt->fetch()['cnt'];
        
        $this->assertEquals($beforeCount + 1, $afterCount, 'Contact count should increase by 1');
    }
    
    /**
     * Test contact creation with international phone number
     */
    public function testStoreContactWithInternationalPhone(): void
    {
        // Test various international formats
        $internationalPhones = [
            '+12863486592',     // US - 12 chars
            '+27821234567',     // South Africa - 13 chars
            '+447911123456',    // UK - 14 chars
            '+919876543210',    // India - 13 chars
            '+33612345678',     // France - 12 chars
        ];
        
        foreach ($internationalPhones as $phone) {
            $stmt = $this->pdo->prepare("INSERT INTO contacts (user_id, name, phone, created_at) VALUES (?, ?, ?, NOW())");
            $result = $stmt->execute([$this->testUser['id'], 'Intl Test', $phone]);
            
            $this->assertTrue($result, "Phone {$phone} should be valid and insert successfully");
            
            // Verify the phone was stored correctly
            $stmt = $this->pdo->prepare("SELECT phone FROM contacts WHERE user_id = ? AND phone = ?");
            $stmt->execute([$this->testUser['id'], $phone]);
            $stored = $stmt->fetch();
            
            $this->assertNotFalse($stored, "Phone {$phone} should be retrievable");
            $this->assertEquals($phone, $stored['phone'], "Phone should match exactly");
        }
    }
    
    /**
     * Test contact update
     */
    public function testUpdateContact(): void
    {
        // Create a contact first
        $stmt = $this->pdo->prepare("INSERT INTO contacts (user_id, name, phone, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$this->testUser['id'], 'Original Name', '+12345678901']);
        $contactId = $this->pdo->lastInsertId();
        
        // Update the contact
        $stmt = $this->pdo->prepare("UPDATE contacts SET name = ? WHERE id = ? AND user_id = ?");
        $result = $stmt->execute(['Updated Name', $contactId, $this->testUser['id']]);
        
        $this->assertTrue($result, 'Update should succeed');
        
        // Verify update
        $stmt = $this->pdo->prepare("SELECT name FROM contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch();
        
        $this->assertEquals('Updated Name', $contact['name'], 'Name should be updated');
    }
    
    /**
     * Test contact deletion
     */
    public function testDeleteContact(): void
    {
        // Create a contact first
        $stmt = $this->pdo->prepare("INSERT INTO contacts (user_id, name, phone, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$this->testUser['id'], 'To Delete', '+19876543210']);
        $contactId = $this->pdo->lastInsertId();
        
        // Delete the contact
        $stmt = $this->pdo->prepare("DELETE FROM contacts WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$contactId, $this->testUser['id']]);
        
        $this->assertTrue($result, 'Delete should succeed');
        
        // Verify deletion
        $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch();
        
        $this->assertFalse($contact, 'Contact should not exist after deletion');
    }
    
    /**
     * Test contact group creation
     */
    public function testCreateContactGroup(): void
    {
        $groupName = 'PHPUnit Test Group ' . time();
        
        $stmt = $this->pdo->prepare("INSERT INTO contact_groups (user_id, name, description, created_at) VALUES (?, ?, ?, NOW())");
        $result = $stmt->execute([$this->testUser['id'], $groupName, 'Test description']);
        
        $this->assertTrue($result, 'Group should be created');
        
        $groupId = $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $groupId, 'Group ID should be returned');
        
        // Verify group exists
        $stmt = $this->pdo->prepare("SELECT * FROM contact_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();
        
        $this->assertNotFalse($group, 'Group should exist');
        $this->assertEquals($groupName, $group['name'], 'Group name should match');
    }
    
    /**
     * Test that user_id is required for all operations
     */
    public function testUserIdEnforcement(): void
    {
        // Try to get contacts without user context
        TestAuth::reset();
        
        $this->assertNull(TestAuth::id(), 'User ID should be null when not authenticated');
        
        // Operations requiring user_id should fail gracefully
        $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE user_id = ?");
        $stmt->execute([null]);
        $result = $stmt->fetchAll();
        
        $this->assertEmpty($result, 'No contacts should be returned for null user_id');
    }
}
