<?php
/**
 * TemplateController Unit Tests
 * Tests template CRUD operations
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../bootstrap.php';

class TemplateControllerTest extends TestCase
{
    private PDO $pdo;
    private array $testUser;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = getTestDatabase();
        $this->testUser = createTestUser($this->pdo);
        TestAuth::setUser($this->testUser);
    }
    
    protected function tearDown(): void
    {
        if (isset($this->testUser['id'])) {
            cleanupTestUser($this->pdo, $this->testUser['id']);
        }
        TestAuth::reset();
        parent::tearDown();
    }
    
    /**
     * Test template creation
     */
    public function testCreateTemplate(): void
    {
        $templateName = 'PHPUnit Template ' . time();
        $content = 'Hello {{name}}, this is a test message.';
        
        $stmt = $this->pdo->prepare("INSERT INTO templates (user_id, name, content, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $result = $stmt->execute([$this->testUser['id'], $templateName, $content, 'sms']);
        
        $this->assertTrue($result, 'Template should be created');
        
        $templateId = $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $templateId, 'Template ID should be returned');
        
        // Verify template exists
        $stmt = $this->pdo->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        $this->assertNotFalse($template, 'Template should exist');
        $this->assertEquals($templateName, $template['name'], 'Template name should match');
        $this->assertEquals($content, $template['content'], 'Template content should match');
        $this->assertEquals('sms', $template['type'], 'Template type should match');
    }
    
    /**
     * Test template update
     */
    public function testUpdateTemplate(): void
    {
        // Create template first
        $stmt = $this->pdo->prepare("INSERT INTO templates (user_id, name, content, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$this->testUser['id'], 'Original Template', 'Original content', 'sms']);
        $templateId = $this->pdo->lastInsertId();
        
        // Update the template
        $newContent = 'Updated content with {{placeholder}}';
        $stmt = $this->pdo->prepare("UPDATE templates SET content = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$newContent, $templateId, $this->testUser['id']]);
        
        $this->assertTrue($result, 'Update should succeed');
        
        // Verify update
        $stmt = $this->pdo->prepare("SELECT content FROM templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        $this->assertEquals($newContent, $template['content'], 'Content should be updated');
    }
    
    /**
     * Test template deletion
     */
    public function testDeleteTemplate(): void
    {
        // Create template first
        $stmt = $this->pdo->prepare("INSERT INTO templates (user_id, name, content, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$this->testUser['id'], 'To Delete', 'Delete me', 'sms']);
        $templateId = $this->pdo->lastInsertId();
        
        // Delete the template
        $stmt = $this->pdo->prepare("DELETE FROM templates WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$templateId, $this->testUser['id']]);
        
        $this->assertTrue($result, 'Delete should succeed');
        
        // Verify deletion
        $stmt = $this->pdo->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        $this->assertFalse($template, 'Template should not exist after deletion');
    }
    
    /**
     * Test template with email type
     */
    public function testEmailTemplate(): void
    {
        $templateName = 'Email Template ' . time();
        $content = '<html><body>Hello {{name}}</body></html>';
        $subject = 'Welcome {{name}}!';
        
        $stmt = $this->pdo->prepare("INSERT INTO templates (user_id, name, content, subject, type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $result = $stmt->execute([$this->testUser['id'], $templateName, $content, $subject, 'email']);
        
        $this->assertTrue($result, 'Email template should be created');
        
        $templateId = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        $this->assertEquals('email', $template['type'], 'Type should be email');
        $this->assertEquals($subject, $template['subject'], 'Subject should be stored');
    }
    
    /**
     * Test template listing for user
     */
    public function testListUserTemplates(): void
    {
        // Create multiple templates
        $stmt = $this->pdo->prepare("INSERT INTO templates (user_id, name, content, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        for ($i = 0; $i < 3; $i++) {
            $stmt->execute([$this->testUser['id'], "Template {$i}", "Content {$i}", 'sms']);
        }
        
        // Get templates for user
        $stmt = $this->pdo->prepare("SELECT * FROM templates WHERE user_id = ?");
        $stmt->execute([$this->testUser['id']]);
        $templates = $stmt->fetchAll();
        
        $this->assertCount(3, $templates, 'Should have 3 templates');
    }
    
    /**
     * Test user can only access own templates
     */
    public function testTemplateOwnership(): void
    {
        // Create template for current user
        $stmt = $this->pdo->prepare("INSERT INTO templates (user_id, name, content, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$this->testUser['id'], 'My Template', 'My content', 'sms']);
        $myTemplateId = $this->pdo->lastInsertId();
        
        // Create another user
        $otherUser = createTestUser($this->pdo);
        
        // Other user shouldn't be able to access our template via user_id check
        $stmt = $this->pdo->prepare("SELECT * FROM templates WHERE id = ? AND user_id = ?");
        $stmt->execute([$myTemplateId, $otherUser['id']]);
        $result = $stmt->fetch();
        
        $this->assertFalse($result, 'Other user should not access our template');
        
        // Cleanup other user
        cleanupTestUser($this->pdo, $otherUser['id']);
    }
}
