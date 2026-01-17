# FIX #5: Contact Group Data NOT Returned in API Response

## Problem
When retrieving a single contact, the group information (group_id, group_name) is not included in the response. This means:
1. Edit modal doesn't show current group
2. Contact details page can't display group
3. Frontend has no way to know which group a contact belongs to

### Root Cause
The `show()` and `index()` functions only return data from the `contacts` table. Group information is in the `group_contacts` junction table and requires a JOIN to retrieve.

## Changes Made

### 1. Enhanced `show()` Method
**Before:**
```php
public function show(array $params): void {
    $contact = table('contacts')
        ->where('id', $params['id'])
        ->where('user_id', Auth::id())
        ->first();
    
    if (!$contact) {
        Response::error('Contact not found', 404);
    }
    
    Response::success(['contact' => $contact]); // ❌ No group data
}
```

**After:**
```php
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
    
    Response::success(['contact' => $contact]); // ✅ With group data
}
```

### 2. Enhanced `index()` Method
Now includes group data in list responses with intelligent JOINs:

**Query Pattern:**
```sql
SELECT c.*,
       GROUP_CONCAT(g.id) as group_id,
       GROUP_CONCAT(g.name) as group_name
FROM contacts c
LEFT JOIN group_contacts gc ON c.id = gc.contact_id
LEFT JOIN contact_groups g ON gc.group_id = g.id
WHERE c.user_id = ?
GROUP BY c.id
ORDER BY c.created_at DESC
LIMIT ? OFFSET ?
```

## Response Format

### Before
```json
{
  "success": true,
  "data": {
    "contact": {
      "id": 123,
      "name": "John Doe",
      "phone": "+27123456789",
      "email": "john@example.com",
      "user_id": 1,
      "created_at": "2024-01-01T00:00:00Z"
    }
  }
}
```

### After
```json
{
  "success": true,
  "data": {
    "contact": {
      "id": 123,
      "name": "John Doe",
      "phone": "+27123456789",
      "email": "john@example.com",
      "user_id": 1,
      "created_at": "2024-01-01T00:00:00Z",
      "group_ids": "5,7",
      "group_names": "Sales,VIP",
      "groups": [
        { "id": 5, "name": "Sales" },
        { "id": 7, "name": "VIP" }
      ],
      "primary_group_id": 5,
      "primary_group_name": "Sales"
    }
  }
}
```

## Frontend Integration

### Edit Modal
```tsx
const group = contact?.primary_group_id || "";
```

### Display
```tsx
{contact?.primary_group_name || "No Group"}
```

## Testing
1. Create contact with group
2. GET `/api/contacts/{id}` - should include `primary_group_id`, `primary_group_name`
3. Get contact list - each contact should have group info
4. Edit contact - dropdown should show current group
5. Contact without group - should show `primary_group_id: null`

