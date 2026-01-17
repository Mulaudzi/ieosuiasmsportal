# FIX #1: Contact Group NOT Persisting After Update

## Problem
When editing a contact and changing its group assignment, the change doesn't persist. After closing and reopening the contact, it shows "No Group" or the old group.

### Root Cause
The `update()` function in `ContactController.php` did not:
1. Validate the `group_id` parameter
2. Update the `group_contacts` junction table when group assignment changed

### Code Before
```php
public function update(array $params): void {
    // ... validation code ...
    $data = Request::validate([
        'name' => 'max:100',
        'phone' => 'max:50',
        'email' => 'email|max:255',
        // ❌ No group_id validation!
    ]);
    
    table('contacts')->where('id', $params['id'])->update($data);
    // ❌ Junction table never updated
}
```

### Code After
```php
public function update(array $params): void {
    // ... validation code ...
    $data = Request::validate([
        'name' => 'max:100',
        'phone' => 'max:50',
        'email' => 'email|max:255',
        'group_id' => 'exists:contact_groups,id',  // ✅ Added
    ]);
    
    table('contacts')->where('id', $params['id'])->update($data);
    
    // ✅ NEW: Handle group assignment
    if (isset($data['group_id'])) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Delete existing group assignments
            table('group_contacts')->where('contact_id', $params['id'])->delete();
            
            // Add new group assignment if provided
            if ($data['group_id']) {
                table('group_contacts')->insert([
                    'group_id' => $data['group_id'],
                    'contact_id' => $params['id'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            Response::error('Failed to update group assignment', 500);
            return;
        }
    }
}
```

## Data Flow
1. ✅ Frontend sends: `{ name, phone, email, group_id }`
2. ✅ Backend validates `group_id` against `contact_groups` table
3. ✅ Updates contact record
4. ✅ Updates `group_contacts` junction table
5. ✅ Returns contact with group info
6. ✅ UI shows group correctly

## Testing
1. Edit a contact and change its group
2. Save and verify group displays immediately
3. Close modal and reopen - group should persist
4. Refresh page - group should still be there

