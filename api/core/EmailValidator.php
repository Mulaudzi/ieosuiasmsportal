<?php
/**
 * Email Validator - Validates emails against disposable domains, role-based addresses, and MX records
 */

class EmailValidator {
    private static ?array $config = null;
    private static ?array $dbDomainsCache = null;
    private static int $cacheTime = 0;
    private const CACHE_TTL = 300; // 5 minutes
    
    /**
     * Validate an email address through the full pipeline
     * @param string $email Email to validate
     * @return array ['valid' => bool, 'error' => ?string]
     */
    public static function validate(string $email): array {
        // Step 1: Format validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'error' => 'Invalid email format'];
        }
        
        $email = strtolower(trim($email));
        $parts = explode('@', $email);
        
        if (count($parts) !== 2) {
            return ['valid' => false, 'error' => 'Invalid email format'];
        }
        
        $localPart = $parts[0];
        $domain = $parts[1];
        
        // Step 2: Role-based email blocking
        $roleCheck = self::checkRoleBasedEmail($localPart);
        if (!$roleCheck['valid']) {
            return $roleCheck;
        }
        
        // Step 3: Disposable domain blocking
        $disposableCheck = self::checkDisposableDomain($domain);
        if (!$disposableCheck['valid']) {
            return $disposableCheck;
        }
        
        // Step 4: MX record validation
        $mxCheck = self::checkMxRecord($domain);
        if (!$mxCheck['valid']) {
            return $mxCheck;
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Check if email uses a role-based prefix
     */
    public static function checkRoleBasedEmail(string $localPart): array {
        $config = self::getConfig();
        $blockedPrefixes = $config['blocked_role_prefixes'] ?? [];
        
        // Also check DB for custom role patterns
        $dbRoles = self::getDbBlockedDomains('role');
        
        foreach ($blockedPrefixes as $prefix) {
            if ($localPart === $prefix || str_starts_with($localPart, $prefix . '.') || str_starts_with($localPart, $prefix . '+')) {
                error_log("Role-based email blocked: {$localPart}@...");
                return ['valid' => false, 'error' => 'Generic role-based email addresses are not allowed. Please use a personal email.'];
            }
        }
        
        // Check DB patterns
        foreach ($dbRoles as $pattern) {
            $pattern = rtrim($pattern, '%@');
            if ($localPart === $pattern || str_starts_with($localPart, $pattern)) {
                error_log("Role-based email blocked (DB): {$localPart}@...");
                return ['valid' => false, 'error' => 'Generic role-based email addresses are not allowed. Please use a personal email.'];
            }
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Check if domain is a known disposable email provider
     */
    public static function checkDisposableDomain(string $domain): array {
        $config = self::getConfig();
        $disposableDomains = $config['disposable_domains'] ?? [];
        
        // Check JSON config
        if (in_array($domain, $disposableDomains, true)) {
            error_log("Disposable email domain blocked (config): {$domain}");
            return ['valid' => false, 'error' => 'Temporary or disposable email addresses are not allowed.'];
        }
        
        // Check DB for additional domains
        $dbDomains = self::getDbBlockedDomains('disposable');
        if (in_array($domain, $dbDomains, true)) {
            error_log("Disposable email domain blocked (DB): {$domain}");
            return ['valid' => false, 'error' => 'Temporary or disposable email addresses are not allowed.'];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Check if domain has valid MX records
     */
    public static function checkMxRecord(string $domain): array {
        // Skip MX check for well-known providers (performance optimization)
        $trustedDomains = [
            'gmail.com', 'googlemail.com', 'google.com',
            'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
            'yahoo.com', 'yahoo.co.uk', 'ymail.com',
            'icloud.com', 'me.com', 'mac.com',
            'protonmail.com', 'proton.me', 'pm.me',
            'aol.com', 'zoho.com', 'mail.com',
            'gmx.com', 'gmx.net', 'fastmail.com'
        ];
        
        if (in_array($domain, $trustedDomains, true)) {
            return ['valid' => true, 'error' => null];
        }
        
        // Check MX records
        if (!checkdnsrr($domain, 'MX')) {
            // Fallback to A record check (some domains use A records for mail)
            if (!checkdnsrr($domain, 'A')) {
                error_log("No MX/A records for domain: {$domain}");
                return ['valid' => false, 'error' => 'The email domain does not appear to be valid.'];
            }
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Load config from JSON file
     */
    private static function getConfig(): array {
        if (self::$config === null) {
            $configPath = __DIR__ . '/../config/disposable_domains.json';
            if (file_exists($configPath)) {
                $content = file_get_contents($configPath);
                self::$config = json_decode($content, true) ?? [];
            } else {
                self::$config = [];
            }
        }
        return self::$config;
    }
    
    /**
     * Get blocked domains from database (with caching)
     */
    private static function getDbBlockedDomains(string $type): array {
        $now = time();
        
        // Check cache
        if (self::$dbDomainsCache !== null && ($now - self::$cacheTime) < self::CACHE_TTL) {
            return self::$dbDomainsCache[$type] ?? [];
        }
        
        try {
            $pdo = db();
            
            // Check if table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'blocked_email_domains'");
            if ($stmt->rowCount() === 0) {
                self::$dbDomainsCache = ['disposable' => [], 'role' => []];
                self::$cacheTime = $now;
                return [];
            }
            
            $stmt = $pdo->query("SELECT domain, type FROM blocked_email_domains");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::$dbDomainsCache = ['disposable' => [], 'role' => []];
            foreach ($rows as $row) {
                self::$dbDomainsCache[$row['type']][] = $row['domain'];
            }
            self::$cacheTime = $now;
            
            return self::$dbDomainsCache[$type] ?? [];
        } catch (PDOException $e) {
            error_log("EmailValidator DB error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add a domain to the blocklist (DB)
     */
    public static function addBlockedDomain(string $domain, string $type = 'disposable'): bool {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT IGNORE INTO blocked_email_domains (domain, type) VALUES (:domain, :type)");
            $stmt->execute(['domain' => strtolower(trim($domain)), 'type' => $type]);
            
            // Clear cache
            self::$dbDomainsCache = null;
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to add blocked domain: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove a domain from the blocklist (DB)
     */
    public static function removeBlockedDomain(string $domain): bool {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("DELETE FROM blocked_email_domains WHERE domain = :domain");
            $stmt->execute(['domain' => strtolower(trim($domain))]);
            
            // Clear cache
            self::$dbDomainsCache = null;
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to remove blocked domain: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Seed the database from the JSON config
     */
    public static function seedFromConfig(): int {
        $config = self::getConfig();
        $count = 0;
        
        // Seed disposable domains
        foreach ($config['disposable_domains'] ?? [] as $domain) {
            if (self::addBlockedDomain($domain, 'disposable')) {
                $count++;
            }
        }
        
        // Seed role prefixes as patterns
        foreach ($config['blocked_role_prefixes'] ?? [] as $prefix) {
            if (self::addBlockedDomain($prefix . '@%', 'role')) {
                $count++;
            }
        }
        
        return $count;
    }
}
