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
            'name' => 'required|max:100',
            'phone' => 'required|max:20',
            'email' => 'email|max:255',
            'group_id' => 'exists:contact_groups,id',
        ]);
        
        $contactId = table('contacts')->insert([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Add to group if specified
        if (isset($data['group_id'])) {
            table('group_contacts')->insert([
                'group_id' => $data['group_id'],
                'contact_id' => $contactId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        $contact = table('contacts')->where('id', $contactId)->first();
        Response::created(['contact' => $contact]);
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
            'phone' => 'max:20',
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
        
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded', 400);
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'])) {
            Response::error('Only CSV files are allowed', 400);
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            Response::error('Failed to read file', 500);
        }
        
        $header = fgetcsv($handle);
        $imported = 0;
        $failed = 0;
        $userId = Auth::id();
        
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            
            if (empty($data['phone']) && empty($data['Phone'])) {
                $failed++;
                continue;
            }
            
            try {
                table('contacts')->insert([
                    'user_id' => $userId,
                    'name' => $data['name'] ?? $data['Name'] ?? 'Unknown',
                    'phone' => $data['phone'] ?? $data['Phone'],
                    'email' => $data['email'] ?? $data['Email'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $imported++;
            } catch (Exception $e) {
                $failed++;
            }
        }
        
        fclose($handle);
        
        Response::success([
            'imported' => $imported,
            'failed' => $failed,
            'message' => "Imported $imported contacts, $failed failed",
        ]);
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
        ]);
        
        $groupId = table('contact_groups')->insert([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $group = table('contact_groups')->where('id', $groupId)->first();
        $group['contact_count'] = 0;
        
        Response::created(['group' => $group]);
    }
    
    public function deleteGroup(array $params): void {
        $group = table('contact_groups')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$group) {
            Response::error('Group not found', 404);
        }
        
        table('group_contacts')->where('group_id', $params['id'])->delete();
        table('contact_groups')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
}
