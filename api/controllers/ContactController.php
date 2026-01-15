<?php
/**
 * Contact Controller
 */

class ContactController {
    public function index(): void {
        $userId = Auth::id();
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        $search = Request::query('search', '');
        $groupId = Request::query('group_id');
        
        $query = table('contacts')->where('user_id', $userId);
        
        if ($search) {
            // Simple search - in production use full-text search
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT * FROM contacts 
                WHERE user_id = ? AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $searchTerm = "%$search%";
            $stmt->execute([$userId, $searchTerm, $searchTerm, $searchTerm, $perPage, ($page - 1) * $perPage]);
            $contacts = $stmt->fetchAll();
            
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM contacts 
                WHERE user_id = ? AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
            ");
            $countStmt->execute([$userId, $searchTerm, $searchTerm, $searchTerm]);
            $total = $countStmt->fetch()['count'];
        } elseif ($groupId) {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT c.* FROM contacts c
                JOIN group_contacts gc ON c.id = gc.contact_id
                WHERE gc.group_id = ? AND c.user_id = ?
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$groupId, $userId, $perPage, ($page - 1) * $perPage]);
            $contacts = $stmt->fetchAll();
            
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM contacts c
                JOIN group_contacts gc ON c.id = gc.contact_id
                WHERE gc.group_id = ? AND c.user_id = ?
            ");
            $countStmt->execute([$groupId, $userId]);
            $total = $countStmt->fetch()['count'];
        } else {
            $total = table('contacts')->where('user_id', $userId)->count();
            $contacts = table('contacts')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'DESC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();
        }
        
        Response::paginate($contacts, $total, $page, $perPage);
    }
    
    public function store(): void {
        $data = Request::validate([
            'name' => 'max:100',
            'surname' => 'max:100',
            'phone' => 'max:50',  // Increased for international numbers with country codes
            'email' => 'email|max:255',
            'country_code' => 'max:10',
            'group_id' => 'exists:contact_groups,id',
        ]);
        
        // Set defaults
        $name = $data['name'] ?? 'Esteemed';
        $phone = $data['phone'] ?? null;
        $email = $data['email'] ?? null;
        
        // Require at least phone or email
        if (!$phone && !$email) {
            Response::error('Either phone or email is required', 400);
        }
        
        // Clean and validate phone if provided
        if ($phone) {
            // Remove any non-numeric characters except + at start
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            // Ensure it starts with + for E.164 format
            if ($phone && $phone[0] !== '+' && !empty($data['country_code'])) {
                $phone = $data['country_code'] . ltrim($phone, '0');
            }
        }
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            $contactId = table('contacts')->insert([
                'user_id' => Auth::id(),
                'name' => $name,
                'surname' => $data['surname'] ?? null,
                'phone' => $phone,
                'email' => $email,
                'country_code' => $data['country_code'] ?? '+27',
                'subscription_status' => 'subscribed',
                'subscribed_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            if (!$contactId) {
                throw new Exception('Failed to create contact - no ID returned');
            }
            
            // Add to group if specified
            if (isset($data['group_id'])) {
                table('group_contacts')->insert([
                    'group_id' => $data['group_id'],
                    'contact_id' => $contactId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            
            $pdo->commit();
            
            $contact = table('contacts')->where('id', $contactId)->first();
            Response::created(['contact' => $contact]);
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log('Contact store error: ' . $e->getMessage());
            Response::error('Failed to create contact: ' . $e->getMessage(), 500);
        }
    }
    
    public function show(array $params): void {
        $contact = table('contacts')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$contact) {
            Response::error('Contact not found', 404);
        }
        
        Response::success(['contact' => $contact]);
    }
    
    public function update(array $params): void {
        $contact = table('contacts')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$contact) {
            Response::error('Contact not found', 404);
        }
        
        $data = Request::validate([
            'name' => 'max:100',
            'phone' => 'max:50',  // Increased for international numbers
            'email' => 'email|max:255',
        ]);
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        table('contacts')->where('id', $params['id'])->update($data);
        
        $contact = table('contacts')->where('id', $params['id'])->first();
        Response::success(['contact' => $contact]);
    }
    
    public function destroy(array $params): void {
        $contact = table('contacts')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$contact) {
            Response::error('Contact not found', 404);
        }
        
        table('group_contacts')->where('contact_id', $params['id'])->delete();
        table('contacts')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
    
    public function import(): void {
        $file = Request::file('file');
        $skipDuplicates = Request::query('skip_duplicates', 'true') === 'true';
        $groupId = Request::query('group_id');
        
        // Validate group_id ownership if provided
        if ($groupId) {
            if (!is_numeric($groupId)) {
                Response::error('Invalid group ID', 400);
            }
            $group = table('contact_groups')
                ->where('id', $groupId)
                ->where('user_id', Auth::id())
                ->first();
            if (!$group) {
                Response::error('Group not found or access denied', 404);
            }
        }
        
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded', 400);
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'xlsx', 'xls'])) {
            Response::error('Only CSV and Excel files are allowed', 400);
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            Response::error('Failed to read file', 500);
        }
        
        $header = fgetcsv($handle);
        $header = array_map('strtolower', array_map('trim', $header));
        
        $imported = 0;
        $failed = 0;
        $duplicates = 0;
        $userId = Auth::id();
        $pdo = db();
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                $failed++;
                continue;
            }
            
            $data = array_combine($header, $row);
            
            // Map common column variations
            $name = $data['name'] ?? $data['first_name'] ?? $data['firstname'] ?? 'Esteemed';
            $surname = $data['surname'] ?? $data['last_name'] ?? $data['lastname'] ?? null;
            $phone = $data['phone'] ?? $data['mobile'] ?? $data['cell'] ?? $data['telephone'] ?? null;
            $email = $data['email'] ?? $data['e-mail'] ?? null;
            $countryCode = $data['country_code'] ?? $data['country'] ?? '+27';
            
            // Clean phone number
            if ($phone) {
                $phone = preg_replace('/[^0-9+]/', '', $phone);
            }
            
            // Skip if no phone and no email
            if (empty($phone) && empty($email)) {
                $failed++;
                continue;
            }
            
            // Check for duplicates
            if ($skipDuplicates && $phone) {
                $existing = table('contacts')
                    ->where('user_id', $userId)
                    ->where('phone', $phone)
                    ->first();
                
                if ($existing) {
                    $duplicates++;
                    continue;
                }
            }
            
            try {
                $contactId = table('contacts')->insert([
                    'user_id' => $userId,
                    'name' => $name,
                    'surname' => $surname,
                    'phone' => $phone,
                    'email' => $email ?: null,
                    'country_code' => $countryCode,
                    'subscription_status' => 'subscribed',
                    'subscribed_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                // Add to group if specified
                if ($groupId && $contactId) {
                    table('group_contacts')->insert([
                        'group_id' => $groupId,
                        'contact_id' => $contactId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                
                $imported++;
            } catch (Exception $e) {
                $failed++;
            }
        }
        
        fclose($handle);
        
        Response::success([
            'imported' => $imported,
            'failed' => $failed,
            'duplicates' => $duplicates,
            'message' => "Imported $imported contacts" . 
                ($duplicates > 0 ? ", $duplicates duplicates skipped" : "") . 
                ($failed > 0 ? ", $failed failed" : ""),
        ]);
    }
    
    public function export(): void {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                Response::error('Unauthorized - user not loaded', 401);
                return;
            }
            
            $groupId = Request::query('group_id');
            
            if ($groupId) {
                $pdo = db();
                $stmt = $pdo->prepare("
                    SELECT c.* FROM contacts c
                    JOIN group_contacts gc ON c.id = gc.contact_id
                    WHERE gc.group_id = ? AND c.user_id = ?
                    ORDER BY c.name ASC
                ");
                $stmt->execute([$groupId, $userId]);
                $contacts = $stmt->fetchAll();
            } else {
                $contacts = table('contacts')
                    ->where('user_id', $userId)
                    ->orderBy('name', 'ASC')
                    ->get();
            }
            
            // Generate CSV
            $output = fopen('php://temp', 'r+');
            fputcsv($output, ['Name', 'Surname', 'Phone', 'Email', 'Country Code', 'Status', 'Created At']);
            
            foreach ($contacts as $contact) {
                fputcsv($output, [
                    $contact['name'],
                    $contact['surname'] ?? '',
                    $contact['phone'] ?? '',
                    $contact['email'] ?? '',
                    $contact['country_code'] ?? '+27',
                    $contact['subscription_status'] ?? 'subscribed',
                    $contact['created_at'],
                ]);
            }
            
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);
            
            // Clear any output buffers to prevent JSON wrapper
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="contacts_export_' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $csv;
            exit;
        } catch (Exception $e) {
            error_log('Export contacts error: ' . $e->getMessage());
            Response::error('Failed to export contacts: ' . $e->getMessage(), 500);
        }
    }
    
    public function groups(): void {
        $groups = table('contact_groups')
            ->where('user_id', Auth::id())
            ->orderBy('name', 'ASC')
            ->get();
        
        // Add contact counts
        foreach ($groups as &$group) {
            $group['contact_count'] = table('group_contacts')
                ->where('group_id', $group['id'])
                ->count();
        }
        
        Response::success(['groups' => $groups]);
    }
    
    public function createGroup(): void {
        $data = Request::validate([
            'name' => 'required|max:100',
            'description' => 'max:500',
        ]);
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            $insertData = [
                'user_id' => Auth::id(),
                'name' => $data['name'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            // Add description if provided
            if (!empty($data['description'])) {
                $insertData['description'] = $data['description'];
            }
            
            $groupId = table('contact_groups')->insert($insertData);
            
            $pdo->commit();
            
            $group = table('contact_groups')->where('id', $groupId)->first();
            $group['contact_count'] = 0;
            
            Response::created(['group' => $group]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('createGroup error: ' . $e->getMessage());
            Response::error('Failed to create group', 500);
        }
    }
    
    public function updateGroup(array $params): void {
        // Validate ID is numeric
        if (!is_numeric($params['id'])) {
            Response::error('Invalid group ID', 400);
        }
        
        $group = table('contact_groups')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$group) {
            Response::error('Group not found', 404);
        }
        
        $data = Request::validate([
            'name' => 'required|max:100',
            'description' => 'max:500',
        ]);
        
        try {
            $updateData = [
                'name' => $data['name'],
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            // Update description if provided
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            
            table('contact_groups')->where('id', $params['id'])->update($updateData);
            
            $group = table('contact_groups')->where('id', $params['id'])->first();
            $group['contact_count'] = table('group_contacts')->where('group_id', $group['id'])->count();
            
            Response::success(['group' => $group]);
        } catch (Exception $e) {
            error_log('updateGroup error: ' . $e->getMessage());
            Response::error('Failed to update group', 500);
        }
    }
    
    public function deleteGroup(array $params): void {
        // Validate ID is numeric
        if (!is_numeric($params['id'])) {
            Response::error('Invalid group ID', 400);
        }
        
        $group = table('contact_groups')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$group) {
            Response::error('Group not found', 404);
        }
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            table('group_contacts')->where('group_id', $params['id'])->delete();
            table('contact_groups')->where('id', $params['id'])->delete();
            
            $pdo->commit();
            Response::noContent();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('deleteGroup error: ' . $e->getMessage());
            Response::error('Failed to delete group', 500);
        }
    }
    
    /**
     * Bulk delete contacts
     */
    public function bulkDelete(): void {
        $data = Request::validate([
            'ids' => 'required',
        ]);
        
        $ids = $data['ids'];
        if (!is_array($ids) || empty($ids)) {
            Response::error('Invalid contact IDs', 400);
        }
        
        // Validate all IDs are numeric
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                Response::error('Invalid contact ID in list', 400);
            }
        }
        
        $userId = Auth::id();
        $deleted = 0;
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            foreach ($ids as $id) {
                $contact = table('contacts')
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->first();
                    
                if ($contact) {
                    // Delete group associations
                    table('group_contacts')->where('contact_id', $id)->delete();
                    // Delete the contact
                    table('contacts')->where('id', $id)->delete();
                    $deleted++;
                }
            }
            
            $pdo->commit();
            Response::success(['deleted' => $deleted]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Contact bulk delete error: ' . $e->getMessage());
            Response::error('Failed to delete contacts', 500);
        }
    }
}
