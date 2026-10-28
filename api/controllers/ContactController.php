<?php
/**
 * Contact Controller
 */
require_once __DIR__ . '/../domain/PhoneNumber.php';
require_once __DIR__ . '/../domain/ContactGroupService.php';
require_once __DIR__ . '/../domain/ContactPhoneReview.php';

class ContactController {
    public function index(): void {
        $userId = Auth::id();
        $page = max(1, (int) Request::query('page', 1));
        $perPage = max(1, min(200, (int) Request::query('per_page', 20)));
        $search = Request::query('search', '');
        $groupId = Request::query('group_id');
        // Allowlisted server-side ordering applies across all result pages.
        $order=match((string)Request::query('sort','newest')){
            'oldest'=>'c.created_at ASC,c.id ASC',
            'name'=>'c.name ASC,c.id ASC',
            default=>'c.created_at DESC,c.id DESC',
        };
        
        $pdo = db();
        
        if ($groupId === 'opted-out') {
            $where="c.user_id=? AND EXISTS (SELECT 1 FROM opt_outs o WHERE o.user_id=c.user_id AND o.channel='sms' AND o.recipient=c.phone_normalized)";
            $args=[$userId];
            if($search){$where.=' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';$args=array_merge($args,array_fill(0,3,'%'.$search.'%'));}
            $count=$pdo->prepare('SELECT COUNT(*) FROM contacts c WHERE '.$where);$count->execute($args);$total=(int)$count->fetchColumn();
            $stmt=$pdo->prepare("SELECT c.*,GROUP_CONCAT(g.id) group_id,GROUP_CONCAT(g.name) group_name FROM contacts c LEFT JOIN group_contacts gc ON gc.contact_id=c.id LEFT JOIN contact_groups g ON g.id=gc.group_id AND g.user_id=c.user_id WHERE {$where} GROUP BY c.id ORDER BY {$order} LIMIT ? OFFSET ?");
            $stmt->execute(array_merge($args,[$perPage,($page-1)*$perPage]));$contacts=$stmt->fetchAll();
        } elseif ($search) {
            $searchGroupSql = '';
            $searchGroupArgs = [];
            if ($groupId === 'uncategorized') {
                $searchGroupSql = ' AND NOT EXISTS (SELECT 1 FROM group_contacts membership WHERE membership.contact_id=c.id)';
            } elseif ($groupId) {
                $searchGroupSql = ' AND EXISTS (SELECT 1 FROM group_contacts membership JOIN contact_groups owned_group ON owned_group.id=membership.group_id WHERE membership.contact_id=c.id AND membership.group_id=? AND owned_group.user_id=c.user_id)';
                $searchGroupArgs[] = $groupId;
            }
            // Search with group information
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       GROUP_CONCAT(g.id) as group_id,
                       GROUP_CONCAT(g.name) as group_name
                FROM contacts c
                LEFT JOIN group_contacts gc ON c.id = gc.contact_id
                LEFT JOIN contact_groups g ON gc.group_id = g.id
                WHERE c.user_id = ? AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?) {$searchGroupSql}
                GROUP BY c.id
                ORDER BY {$order}
                LIMIT ? OFFSET ?
            ");
            $searchTerm = "%$search%";
            $stmt->execute(array_merge([$userId, $searchTerm, $searchTerm, $searchTerm], $searchGroupArgs, [$perPage, ($page - 1) * $perPage]));
            $contacts = $stmt->fetchAll();
            
            $countStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT c.id) as count FROM contacts c
                WHERE c.user_id = ? AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?) {$searchGroupSql}
            ");
            $countStmt->execute(array_merge([$userId, $searchTerm, $searchTerm, $searchTerm], $searchGroupArgs));
            $total = $countStmt->fetch()['count'];
        } elseif ($groupId) {
            // Filter by group with group information
            if ($groupId === 'uncategorized') {
                $stmt = $pdo->prepare("
                    SELECT c.* 
                    FROM contacts c
                    WHERE c.user_id = ?
                    AND c.id NOT IN (
                        SELECT DISTINCT contact_id FROM group_contacts
                    )
                    ORDER BY {$order}
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$userId, $perPage, ($page - 1) * $perPage]);
                $contacts = $stmt->fetchAll();
                
                $countStmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM contacts c
                    WHERE c.user_id = ?
                    AND c.id NOT IN (
                        SELECT DISTINCT contact_id FROM group_contacts
                    )
                ");
                $countStmt->execute([$userId]);
                $total = $countStmt->fetch()['count'];
            } else {
                $stmt = $pdo->prepare("
                    SELECT c.*,
                           g.id as group_id,
                           g.name as group_name
                    FROM contacts c
                    JOIN group_contacts gc ON c.id = gc.contact_id
                    JOIN contact_groups g ON gc.group_id = g.id
                    WHERE gc.group_id = ? AND c.user_id = ?
                    ORDER BY {$order}
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
            }
        } else {
            // All contacts with group information
            $stmt = $pdo->prepare("
                SELECT c.*,
                       GROUP_CONCAT(g.id) as group_id,
                       GROUP_CONCAT(g.name) as group_name
                FROM contacts c
                LEFT JOIN group_contacts gc ON c.id = gc.contact_id
                LEFT JOIN contact_groups g ON gc.group_id = g.id
                WHERE c.user_id = ?
                GROUP BY c.id
                ORDER BY {$order}
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $perPage, ($page - 1) * $perPage]);
            $contacts = $stmt->fetchAll();
            
            $total = table('contacts')->where('user_id', $userId)->count();
        }
        
        $optouts=$pdo->prepare("SELECT recipient FROM opt_outs WHERE user_id=? AND channel='sms'");$optouts->execute([$userId]);$suppressed=array_flip($optouts->fetchAll(PDO::FETCH_COLUMN));
        foreach($contacts as &$contact){$contact['subscription_status']=isset($suppressed[$contact['phone_normalized']??''])?'unsubscribed':($contact['subscription_status']??'subscribed');}unset($contact);
        $count=$pdo->prepare("SELECT COUNT(*) FROM contacts c WHERE c.user_id=? AND EXISTS (SELECT 1 FROM opt_outs o WHERE o.user_id=c.user_id AND o.channel='sms' AND o.recipient=c.phone_normalized)");$count->execute([$userId]);
        $optedOutTotal=(int)$count->fetchColumn();
        // Account-wide read-only summary, independent of search/group/pagination.
        $recent=$pdo->prepare('SELECT COUNT(*) FROM contacts WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND created_at<=NOW()');$recent->execute([$userId]);
        Response::success(['data'=>$contacts,'meta'=>['current_page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>ceil($total/$perPage),'opted_out_total'=>$optedOutTotal,'recently_added_total'=>(int)$recent->fetchColumn()]]);
    }
    
    public function store(): void {
        $data = Request::validate([
            'name' => 'max:100',
            'surname' => 'max:100',
            'phone' => 'max:50',  // Increased for international numbers with country codes
            'email' => 'email|max:255',
            'country_code' => 'max:10',
            'group_id' => '',
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
            try { $phone = PhoneNumber::normalize($phone); }
            catch (InvalidArgumentException $e) { Response::error($e->getMessage(), 422); }
        }
        if (!empty($data['group_id'])) {
            $group = table('contact_groups')->where('id',$data['group_id'])->where('user_id',Auth::id())->first();
            if (!$group) Response::error('Contact group not found',404);
        }
        $existing = $phone ? table('contacts')->where('user_id',Auth::id())->where('phone_normalized',$phone)->first() : null;
        if ($existing) {
            if (empty($data['group_id'])) {
                Response::error('This number is already saved. Choose a group to add the existing contact without creating a duplicate.',409);
            }
            try {
                $membership = ContactGroupService::transfer(db(), (int)Auth::id(), [
                    'mode' => 'copy',
                    'ids' => [(int)$existing['id']],
                    'target_group_id' => $data['group_id'],
                ]);
            } catch (InvalidArgumentException $e) {
                Response::error($e->getMessage(),422); return;
            } catch (DomainException $e) {
                Response::error($e->getMessage(),409); return;
            } catch (Throwable $e) {
                error_log('Existing contact group addition failed: '.get_class($e));
                Response::error('Unable to add the existing contact to this group. Please refresh and try again.',500); return;
            }
            Response::success([
                'contact' => $existing,
                'existing_contact_reused' => true,
                'group_membership_added' => $membership['added'] > 0,
                'already_in_group' => $membership['already_present'] > 0,
            ]);
            return;
        }
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            $contactId = table('contacts')->insert([
                'user_id' => Auth::id(),
                'name' => $name,
                'surname' => $data['surname'] ?? null,
                'phone' => $phone,
                'phone_normalized' => $phone,
                'phone_original' => $data['phone'] ?? null,
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
            error_log('Contact creation failed: '.$e->getMessage());
            Response::error('Failed to create contact', 500);
        }
    }
    
    public function show(array $params): void {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   GROUP_CONCAT(cg.name) as group_names,
                   GROUP_CONCAT(g.id) as group_ids
            FROM contacts c
            LEFT JOIN group_contacts gc ON c.id = gc.contact_id
            LEFT JOIN contact_groups g ON gc.group_id = g.id
            WHERE c.id = ? AND c.user_id = ?
            GROUP BY c.id
        ");
        
        $stmt->execute([$params['id'], Auth::id()]);
        $contact = $stmt->fetch();
        
        if (!$contact) {
            Response::error('Contact not found', 404);
        }
        
        // Parse group information for better handling
        if ($contact['group_ids']) {
            $groupIds = explode(',', $contact['group_ids']);
            $groupNames = explode(',', $contact['group_names']);
            $contact['groups'] = array_map(function($id, $name) {
                return ['id' => (int)$id, 'name' => $name];
            }, $groupIds, $groupNames);
            $contact['primary_group_id'] = (int)$groupIds[0];
            $contact['primary_group_name'] = $groupNames[0];
        } else {
            $contact['groups'] = [];
            $contact['primary_group_id'] = null;
            $contact['primary_group_name'] = null;
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
            'group_id' => '',
        ]);
        
        if (isset($data['phone'])) {
            try { $data['phone_original']=$data['phone']; $data['phone_normalized']=PhoneNumber::normalize($data['phone']); $data['phone']=$data['phone_normalized']; }
            catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
            $duplicate=table('contacts')->where('user_id',Auth::id())->where('phone_normalized',$data['phone_normalized'])->first();
            if($duplicate && (int)$duplicate['id']!==(int)$params['id']){
                if(Request::input('reuse_existing',false)!==true)Response::json(['success'=>false,'code'=>'CONTACT_NUMBER_EXISTS','message'=>'This number is already saved. You can use that existing contact in this contact’s groups without creating a duplicate.'],409);
                if(array_key_exists('group_id',$data))Response::error('Keep existing groups when replacing a mistyped number with a saved contact.',422);
                require_once __DIR__.'/../domain/ContactNumberReplacement.php';
                try{$replacement=ContactNumberReplacement::apply(db(),(int)Auth::id(),(int)$params['id'],$data['phone']);}
                catch(DomainException|InvalidArgumentException $e){Response::error($e->getMessage(),409);}
                catch(Throwable $e){error_log('Contact number replacement failed: '.get_class($e));Response::error('Unable to replace the group contact. No changes were saved.',500);}
                Response::success($replacement);return;
            }
        }
        if (!empty($data['group_id']) && !table('contact_groups')->where('id',$data['group_id'])->where('user_id',Auth::id())->first()) Response::error('Contact group not found',404);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $groupIdForUpdate=$data['group_id']??null; unset($data['group_id']);
        
        table('contacts')->where('id', $params['id'])->update($data);
        
        // NEW: Handle group assignment
        if ($groupIdForUpdate !== null) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                
                // Delete existing group assignments
                table('group_contacts')->where('contact_id', $params['id'])->delete();
                
                // Add new group assignment if provided
                if ($groupIdForUpdate) {
                    table('group_contacts')->insert([
                        'group_id' => $groupIdForUpdate,
                        'contact_id' => $params['id'],
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                
                $pdo->commit();
            } catch (Exception $e) {
                if (isset($pdo)) {
                    $pdo->rollBack();
                }
                error_log('Group assignment error: ' . $e->getMessage());
                error_log('Contact group assignment update failed: '.$e->getMessage());
                Response::error('Failed to update group assignment', 500);
                return;
            }
        }
        
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
        try {
            $file = Request::file('file');
            $groupId = Request::input('group_id');
            
            // Parse column mapping from frontend
            $columnMapping = [];
            $mappingJson = Request::input('column_mapping');
            if ($mappingJson) {
                $columnMapping = json_decode($mappingJson, true) ?? [];
            }
            
            // Validate group_id ownership if provided
            if ($groupId) {
                if (!is_numeric($groupId)) {
                    Response::error('Invalid group ID', 400);
                    return;
                }
                $group = table('contact_groups')
                    ->where('id', $groupId)
                    ->where('user_id', Auth::id())
                    ->first();
                if (!$group) {
                    Response::error('Group not found or access denied', 404);
                    return;
                }
            }
            
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                Response::error('No file uploaded', 400);
                return;
            }
            
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'txt'])) {
                Response::error('Only CSV files are supported', 400);
                return;
            }
            $maxBytes=(int)env('UPLOAD_MAX_BYTES',5242880);
            if((int)$file['size']>$maxBytes){Response::error('Import file exceeds the configured size limit',413);return;}
            
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                Response::error('Failed to read file', 400);
                return;
            }
            
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!$header || empty($header)) {
                fclose($handle);
                Response::error('CSV file is empty or invalid', 400);
                return;
            }
            
            $header = array_map('strtolower', array_map('trim', $header));

            // Opt-in reviewed imports are validated in full before any row is saved.
            // Legacy clients keep their existing behaviour; no client receives silent repairs.
            $reviewVersion = Request::input('phone_review_version');
            $reviewedPhones = null;
            if ($reviewVersion !== null) {
                if ((string)$reviewVersion !== '1') throw new InvalidArgumentException('Unsupported phone review version.');
                if (count(array_unique($header)) !== count($header) || in_array('', $header, true)) throw new InvalidArgumentException('Column headings must be unique and non-empty.');
                $dataOffset = ftell($handle);
                $sourceRows = [];
                while (($sourceRow = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $sourceRows[] = $sourceRow;
                    if (count($sourceRows) > 10000) throw new InvalidArgumentException('Import at most 10,000 contacts at a time.');
                }
                $decisions = json_decode((string)Request::input('phone_review_decisions', '[]'), true, 64, JSON_THROW_ON_ERROR);
                $reviewedPhones = ContactPhoneReview::prepare($header, $sourceRows, (string)($columnMapping['phone'] ?? ''), $decisions);
                fseek($handle, $dataOffset);
            }
            
            $imported = 0;
            $failed = 0;
            $duplicates = 0;
            $existingAddedToGroup = 0;
            $corrected = 0;
            $rejected = 0;
            $userId = Auth::id();
            $pdo = db();
            $rowNumber = 1; // For error reporting (0-indexed in fgetcsv)
            
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rowNumber++;

                if ($reviewedPhones !== null && $reviewedPhones[$rowNumber] === null) { $rejected++; continue; }
                
                // Skip empty rows
                if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                    continue;
                }
                
                if (count($row) !== count($header)) {
                    $failed++;
                    continue;
                }
                
                $data = array_combine($header, $row);
                
                // If column mapping is provided, use it; otherwise fall back to auto-detection
                if (!empty($columnMapping)) {
                    // Use user-selected column mappings
                    $name = '';
                    $surname = '';
                    $phone = '';
                    $email = '';
                    $countryCode = $data['country_code'] ?? $data['country'] ?? '+27';
                    
                    // Extract values based on column mappings
                    if (!empty($columnMapping['name'])) {
                        $nameCol = strtolower(trim($columnMapping['name']));
                        $name = trim($data[$nameCol] ?? '');
                    }
                    
                    if (!empty($columnMapping['surname'])) {
                        $surnameCol = strtolower(trim($columnMapping['surname']));
                        $surname = trim($data[$surnameCol] ?? '');
                    }
                    
                    if (!empty($columnMapping['phone'])) {
                        $phoneCol = strtolower(trim($columnMapping['phone']));
                        $phone = trim($data[$phoneCol] ?? '');
                    }
                    
                    if (!empty($columnMapping['email'])) {
                        $emailCol = strtolower(trim($columnMapping['email']));
                        $email = trim($data[$emailCol] ?? '');
                    }
                } else {
                    // Fall back to auto-detection for backward compatibility
                    $name = trim($data['name'] ?? $data['first_name'] ?? $data['firstname'] ?? '');
                    $surname = trim($data['surname'] ?? $data['last_name'] ?? $data['lastname'] ?? '');
                    $phone = trim($data['phone'] ?? $data['mobile'] ?? $data['cell'] ?? $data['telephone'] ?? '');
                    $email = trim($data['email'] ?? $data['e-mail'] ?? '');
                    $countryCode = $data['country_code'] ?? $data['country'] ?? '+27';
                }
                
                $originalPhone = $phone;
                if ($reviewedPhones !== null) {
                    $phone = $reviewedPhones[$rowNumber]['phone'];
                    $corrected += $reviewedPhones[$rowNumber]['corrected'] ? 1 : 0;
                    $countryCode = '+27';
                } elseif ($phone) {
                    try{$phone=PhoneNumber::normalize($phone);}catch(InvalidArgumentException){$failed++;continue;}
                }
                
                // VALIDATION: Phone is required (mandatory field)
                if (empty($phone)) {
                    $failed++;
                    continue;
                }
                
                // Name/surname are optional - if we have name, combine with surname
                $fullName = '';
                if (!empty($name) && !empty($surname)) {
                    $fullName = $name . ' ' . $surname;
                } elseif (!empty($name)) {
                    $fullName = $name;
                } elseif (!empty($surname)) {
                    $fullName = $surname;
                } else {
                    // If no name or surname provided, use a placeholder
                    $fullName = 'Contact';
                }
                
                try {
                    // Keep contact creation and membership atomic. Existing records are
                    // reused without overwriting names, emails or subscription choices.
                    $pdo->beginTransaction();
                    if ($groupId) {
                        $lock = $pdo->prepare('SELECT id FROM contact_groups WHERE id=? AND user_id=? FOR UPDATE');
                        $lock->execute([$groupId, $userId]);
                        if (!$lock->fetchColumn()) { throw new RuntimeException('Import destination is no longer available'); }
                    }
                    $lookup = $pdo->prepare('SELECT id FROM contacts WHERE user_id=? AND phone_normalized=? FOR UPDATE');
                    $lookup->execute([$userId, $phone]);
                    $existingId = $lookup->fetchColumn();
                    if ($existingId) {
                        $linked = 0;
                        if ($groupId) {
                            $link = $pdo->prepare('INSERT INTO group_contacts (group_id,contact_id,created_at) SELECT ?,?,NOW() WHERE NOT EXISTS (SELECT 1 FROM group_contacts WHERE group_id=? AND contact_id=?)');
                            $link->execute([$groupId, $existingId, $groupId, $existingId]);
                            $linked = $link->rowCount();
                        }
                        $pdo->commit();
                        $duplicates++;
                        $existingAddedToGroup += $linked;
                        continue;
                    }
                    $suppressed = false;
                    if ($reviewedPhones !== null) {
                        $optOut = $pdo->prepare("SELECT 1 FROM opt_outs WHERE user_id=? AND channel='sms' AND recipient=? LIMIT 1");
                        $optOut->execute([$userId, $phone]);
                        $suppressed = (bool)$optOut->fetchColumn();
                    }
                    $contactId = table('contacts')->insert([
                        'user_id' => $userId,
                        'name' => !empty($name) ? $name : '',
                        'surname' => !empty($surname) ? $surname : '',
                        'phone' => $phone ?: null,
                        'phone_normalized' => $phone ?: null,
                        'phone_original' => $reviewedPhones !== null && strlen($originalPhone) <= 50 ? $originalPhone : ($phone ?: null),
                        'email' => $email ?: null,
                        'country_code' => $countryCode,
                        'subscription_status' => $suppressed ? 'unsubscribed' : 'subscribed',
                        'subscribed_at' => $suppressed ? null : date('Y-m-d H:i:s'),
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
                    
                    $pdo->commit();
                    $imported++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log("Contact import row $rowNumber error: " . $e->getMessage());
                    $failed++;
                }
            }
            
            fclose($handle);
            
            Response::success([
                'imported' => $imported,
                'failed' => $failed,
                'duplicates' => $duplicates,
                'existing_added_to_group' => $existingAddedToGroup,
                'corrected' => $corrected,
                'rejected' => $rejected,
                'group_id' => $groupId ? (int)$groupId : null,
                'message' => "Imported $imported contacts" . 
                    ($duplicates > 0 ? ", $duplicates duplicates skipped" : "") . 
                    ($failed > 0 ? ", $failed failed" : ""),
            ]);
        } catch (Exception $e) {
            if (isset($handle) && is_resource($handle)) fclose($handle);
            error_log('Import error: ' . $e->getMessage());
            Response::error('Import failed: ' . $e->getMessage(), 400);
        }
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
            fputcsv($output, ['Name', 'Surname', 'Phone', 'Email', 'Country Code', 'Status', 'Created At'], escape: '');
            
            foreach ($contacts as $contact) {
                fputcsv($output, [
                    $this->csvCell($contact['name']),
                    $this->csvCell($contact['surname'] ?? ''),
                    $this->csvCell($contact['phone'] ?? ''),
                    $this->csvCell($contact['email'] ?? ''),
                    $this->csvCell($contact['country_code'] ?? '+27'),
                    $this->csvCell($contact['subscription_status'] ?? 'subscribed'),
                    $this->csvCell($contact['created_at']),
                ], escape: '');
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
            error_log('Contact export failed: '.$e->getMessage());
            Response::error('Failed to export contacts', 500);
        }
    }

    private function csvCell(mixed $value): string {
        $text = (string) $value;
        return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
    }
    
    public function groups(): void {
        $userId = Auth::id();
        
        // Get actual groups
        $groups = table('contact_groups')
            ->where('user_id', $userId)
            ->orderBy('name', 'ASC')
            ->get();
        
        // Add contact counts
        foreach ($groups as &$group) {
            $group['contact_count'] = (int) table('group_contacts')
                ->where('group_id', $group['id'])
                ->count();
        }
        
        // Count uncategorized contacts (in no group)
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM contacts c
            WHERE c.user_id = ?
            AND c.id NOT IN (
                SELECT DISTINCT contact_id FROM group_contacts
            )
        ");
        $stmt->execute([$userId]);
        $uncategorizedCount = (int) ($stmt->fetch()['count'] ?? 0);
        
        // Add virtual Uncategorized group at start
        array_unshift($groups, [
            'id' => 'uncategorized',
            'name' => 'Uncategorized',
            'description' => 'Contacts with no group assigned',
            'contact_count' => $uncategorizedCount,
            'is_virtual' => true,
        ]);
        
        $total = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE user_id=?');
        $total->execute([$userId]);
        Response::success(['groups' => $groups, 'total_contacts' => (int)$total->fetchColumn()]);
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

    public function bulkAddToGroup(): void {
        $this->performGroupTransfer(['mode'=>'copy', 'ids'=>Request::input('ids'), 'target_group_id'=>Request::input('group_id')]);
    }

    public function transferGroupContacts(): void {
        $this->performGroupTransfer(Request::all());
    }

    private function performGroupTransfer(array $data): void {
        try {
            $result = ContactGroupService::transfer(db(), (int)Auth::id(), $data);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422); return;
        } catch (DomainException $e) {
            Response::error($e->getMessage(), 409); return;
        } catch (Throwable $e) {
            error_log('Contact group transfer failed: ' . $e->getMessage());
            Response::error('The groups could not be updated. Refresh the list before trying again.', 500); return;
        }
        Response::success($result + ['group_id'=>$result['target_group_id']]);
    }
}
