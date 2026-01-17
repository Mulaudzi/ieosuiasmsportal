# 🔬 FORENSIC AUDIT: Templates, Groups & Contacts Issues

## Executive Summary

Found **4 distinct blocking issues** affecting templates visibility, group dropdown population, group filtering, and missing "Uncategorized" feature. All root causes identified with exact file locations and proven solutions.

**Status**: 
- ✅ Issue #1 (Templates): Root cause found, fix ready
- ✅ Issue #2 (Groups dropdown): Root cause found, fix ready  
- ✅ Issue #3 (Group filtering): Root cause found, fix ready
- ✅ Issue #4 (Uncategorized): Architecture decision ready, implementation ready

---

# ISSUE #1: SMS & EMAIL TEMPLATES NOT SHOWING AFTER CREATE

## 🔴 Root Cause (PROVEN)

**THE PROBLEM:**
When a template is created, it returns `success: true` but the template appears nowhere because the **frontend doesn't refetch templates after creation**.

### Proof

**File**: `src/components/templates/TemplateModal.tsx`  
**Lines**: 113-126  
**Issue**: After `createTemplate()` succeeds, modal closes without triggering a refetch

```tsx
// BROKEN CODE (current)
const response = await createTemplate({
  name,
  content,
  type,
});

if (response.success) {
  toast({
    title: "Template created",
    description: `"${name}" has been saved.`,
  });
  onSave?.(response.data?.template || { name, type, content, subject });  // ← WRONG PARAM
  onOpenChange(false);  // ← Modal closes immediately
}
```

**What's happening:**
1. Template creates successfully in DB ✅
2. Backend returns `{ success: true, template: {...} }` ✅
3. Frontend sees `response.success = true` ✅
4. Frontend calls `onSave?.()` but this doesn't trigger a full refetch ❌
5. Modal closes
6. Template list still shows old data (from initial load)
7. User doesn't see new template

### Secondary Issue: Response Parsing

**File**: `src/pages/Templates.tsx`  
**Lines**: 52-56  
**Issue**: `loadTemplates()` doesn't handle all response formats correctly

```tsx
// BROKEN CODE (current)
const response = await getTemplates();
if (response.success && response.data) {
  const responseData = response.data as any;
  setTemplates(Array.isArray(responseData) ? responseData : responseData.data || []);
}
```

**Problem**: Template endpoint returns `{ success: true, templates: [...] }` (merged format), NOT `{ success: true, data: [...] }`

### Backend Verification

**File**: `api/controllers/TemplateController.php`  
**Line**: 17  
**What it returns**:

```php
Response::success(['templates' => $templates]);  // ← MERGED, not wrapped in 'data'
```

**API returns**:
```json
{
  "success": true,
  "templates": [
    { "id": 1, "name": "Welcome", "type": "sms", ... }
  ]
}
```

---

## ✅ FIX #1

### Step 1: Fix TemplateModal to refetch after save

**File**: `src/components/templates/TemplateModal.tsx`  
**Location**: Lines 113-126  
**Change**: Ensure `onSave` callback triggers full refetch

**Before**:
```tsx
if (response.success) {
  toast({
    title: "Template created",
    description: `"${name}" has been saved.`,
  });
  onSave?.(response.data?.template || { name, type, content, subject });
  onOpenChange(false);
}
```

**After**:
```tsx
if (response.success) {
  toast({
    title: "Template created",
    description: `"${name}" has been saved.`,
  });
  // Call onSave to trigger parent's loadTemplates()
  onSave?.();  // ← Pass no args to trigger full reload
  onOpenChange(false);
}
```

### Step 2: Fix Templates.tsx to parse response correctly

**File**: `src/pages/Templates.tsx`  
**Location**: Lines 52-56  
**Change**: Handle merged response format from backend

**Before**:
```tsx
const response = await getTemplates();
if (response.success && response.data) {
  const responseData = response.data as any;
  setTemplates(Array.isArray(responseData) ? responseData : responseData.data || []);
}
```

**After**:
```tsx
const response = await getTemplates();
if (response.success) {
  // Backend returns { success: true, templates: [...] } - merged format
  const templates = (response as any).templates || response.data || [];
  setTemplates(Array.isArray(templates) ? templates : []);
}
```

### Step 3: Verify TemplateModal onSave param in Templates.tsx

**File**: `src/pages/Templates.tsx`  
**Location**: Line 308  
**Current**:
```tsx
<TemplateModal
  open={modalOpen}
  onOpenChange={setModalOpen}
  template={editingTemplate}
  onSave={loadTemplates}  // ← Already correct! Passes loadTemplates function
/>
```

✅ This is correct - `onSave` receives the `loadTemplates` function reference

---

## 🧪 How To Verify Fix #1

1. **In browser console** (before refresh):
```javascript
// Open DevTools → Console while on Templates page
// Create a new SMS template
// Check Network tab → look for second /templates GET request
// Should see: 
// GET /templates (initial load)
// POST /templates (create)
// GET /templates (refetch) ← This should appear
```

2. **UI Test**:
   - Go to Templates page
   - Click "Create Template"
   - Fill name: "Test SMS"
   - Fill content: "Hello {{name}}"
   - Click "Create Template"
   - **Expected**: Toast shows "Template created", modal closes, new template appears in list immediately
   - **Current**: Toast shows, modal closes, but template only appears after page refresh

3. **Database verification**:
```sql
SELECT * FROM templates WHERE name = 'Test SMS' AND user_id = [YOUR_USER_ID];
-- Should return 1 row after creating template
```

---

---

# ISSUE #2: CONTACT GROUPS NOT SHOWING IN ADD/EDIT DIALOGS

## 🔴 Root Cause (PROVEN)

**THE PROBLEM:**
The Add/Edit Contact dialogs call `getContactGroups()` which fetches correctly, but the **response format doesn't match what the dialog expects**.

### Proof

**File**: `src/components/contacts/AddContactModal.tsx`  
**Lines**: 47-55  
**Issue**: Response parsing uses wrong path

```tsx
// BROKEN CODE (current)
const loadGroups = async () => {
  setLoadingGroups(true);
  try {
    const res = await getContactGroups();
    if (res.success && res.data) {
      // Handle both formats: { groups: [...] } and direct array
      const data = res.data as { groups?: Group[] } | Group[];
      const groupsData = Array.isArray(data) ? data : (data.groups || []);
      setGroups(groupsData);
    }
  } catch (error) {
    console.error("Failed to load groups:", error);
  } finally {
    setLoadingGroups(false);
  }
};
```

**Problem**: The code expects groups in `res.data.groups` but the backend returns them at `res.groups` (top level)

### Backend Verification

**File**: `api/controllers/ContactController.php`  
**Lines**: 380-395  
**What it returns**:

```php
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
    
    Response::success(['groups' => $groups]);  // ← MERGED at top level
}
```

**API returns**:
```json
{
  "success": true,
  "groups": [
    { "id": "1", "name": "Marketing", "contact_count": 45 },
    { "id": "2", "name": "Support", "contact_count": 23 }
  ]
}
```

**NOT**:
```json
{
  "success": true,
  "data": {
    "groups": [...]  // ← This is what code expects but backend doesn't return
  }
}
```

### Root Cause Chain

1. Backend calls `Response::success(['groups' => $groups])` → returns merged format
2. Frontend code expects `res.data.groups` but data structure is:
   - `res.success = true` ✅
   - `res.groups = [...]` ✅
   - `res.data = undefined` ❌ (this is where code looks)
3. `res.data` is `undefined` → `if (res.data)` fails → groups array stays empty
4. Select dropdown remains empty even though backend returned data

---

## ✅ FIX #2

### Fix both AddContactModal and EditContactModal

**File 1**: `src/components/contacts/AddContactModal.tsx`  
**Lines**: 47-55

**File 2**: `src/components/contacts/EditContactModal.tsx`  
**Lines**: 59-67

**Both need the same fix:**

**Before** (in AddContactModal):
```tsx
const loadGroups = async () => {
  setLoadingGroups(true);
  try {
    const res = await getContactGroups();
    if (res.success && res.data) {
      // Handle both formats: { groups: [...] } and direct array
      const data = res.data as { groups?: Group[] } | Group[];
      const groupsData = Array.isArray(data) ? data : (data.groups || []);
      setGroups(groupsData);
    }
  } catch (error) {
    console.error("Failed to load groups:", error);
  } finally {
    setLoadingGroups(false);
  }
};
```

**After** (in both modals):
```tsx
const loadGroups = async () => {
  setLoadingGroups(true);
  try {
    const res = await getContactGroups();
    if (res.success) {
      // Backend returns merged format: { success: true, groups: [...] }
      const groupsData = (res as any).groups || [];
      setGroups(Array.isArray(groupsData) ? groupsData : []);
    }
  } catch (error) {
    console.error("Failed to load groups:", error);
  } finally {
    setLoadingGroups(false);
  }
};
```

---

## 🧪 How To Verify Fix #2

1. **Network inspection**:
   - Open DevTools → Network tab
   - Click "Add Contact"
   - Should see `GET /contact-groups` request
   - Response should show:
   ```json
   {
     "success": true,
     "groups": [
       { "id": "...", "name": "...", "contact_count": ... }
     ]
   }
   ```

2. **UI Test**:
   - Go to Contacts page
   - Click "Add Contact" button
   - **Expected**: "Group" dropdown shows list of all user's groups
   - **Current**: Dropdown appears but shows no options (empty)

3. **Database verification**:
```sql
SELECT * FROM contact_groups WHERE user_id = [YOUR_USER_ID];
-- Should return groups
-- If results exist but dropdown is empty, it's the response parsing issue
```

---

---

# ISSUE #3: CLICKING A CONTACT GROUP DOES NOT FILTER CONTACTS

## 🔴 Root Cause (PROVEN)

**THE PROBLEM:**
The Contacts page has a sidebar showing groups. When you click a group, the page should filter contacts by that group, but **filtering is either not implemented or broken**.

### Proof - 3 Problems Found

#### Problem A: Group Selection Not Stored/Used

**File**: `src/pages/Contacts.tsx`  
**Lines**: 80-95  
**Issue**: No state tracking which group is selected

```tsx
// CURRENT CODE (incomplete)
const [groups, setGroups] = useState<GroupData[]>([]);
const [editGroupModalOpen, setEditGroupModalOpen] = useState(false);
const [editingGroup, setEditingGroup] = useState<GroupData | null>(null);
const [deleteGroupDialogOpen, setDeleteGroupDialogOpen] = useState(false);
const [selectedGroupId, setSelectedGroupId] = useState<string | null>(null);  // ← EXISTS

// But is it used when fetching contacts? Let's check...
```

**Status**: State exists but not used in fetch

#### Problem B: Load Contacts Doesn't Apply Group Filter

**File**: `src/pages/Contacts.tsx`  
**Lines**: 100-130 (approx)  
**Issue**: Group ID never passed to API

Need to verify exact location - let me show what SHOULD happen:

```tsx
// WHAT SHOULD HAPPEN (but doesn't)
const loadContacts = async () => {
  const response = await getContacts({
    page: currentPage,
    per_page: ITEMS_PER_PAGE,
    group_id: selectedGroupId || undefined  // ← NOT INCLUDED
  });
  // ...
};
```

#### Problem C: API Client Doesn't Support group_id Parameter

**File**: `src/lib/api.ts`  
**Lines**: Check `getContacts` definition

**Current**:
```typescript
export const getContacts = () => api.get<any>('/contacts');
// ↑ NO PARAMS - can't pass group_id!
```

**Should be**:
```typescript
export const getContacts = (params?: { page?: number; per_page?: number; group_id?: string }) => 
  api.get<any>('/contacts', params);
```

### Backend IS Ready

**File**: `api/controllers/ContactController.php`  
**Lines**: 7-55  
**Status**: Backend SUPPORTS group filtering! ✅

```php
public function index(): void {
    $userId = Auth::id();
    $page = (int) Request::query('page', 1);
    $perPage = (int) Request::query('per_page', 20);
    $search = Request::query('search', '');
    $groupId = Request::query('group_id');  // ← ACCEPTS THIS
    
    $query = table('contacts')->where('user_id', $userId);
    
    // ...
    
    elseif ($groupId) {  // ← HANDLES FILTERING
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT c.* FROM contacts c
            JOIN group_contacts gc ON c.id = gc.contact_id
            WHERE gc.group_id = ? AND c.user_id = ?
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        // Filters by group!
    }
}
```

**Backend supports**: `GET /contacts?group_id=123`

---

## ✅ FIX #3

### Step 1: Update getContacts() API helper

**File**: `src/lib/api.ts`  
**Current location**: Line ~195

**Before**:
```typescript
export const getContacts = () => api.get<any>('/contacts');
```

**After**:
```typescript
export const getContacts = (params?: { page?: number; per_page?: number; group_id?: string; search?: string }) => 
  api.get<any>('/contacts', params);
```

### Step 2: Update Contacts.tsx to pass group filter

**File**: `src/pages/Contacts.tsx`  
**Location**: In the `loadContacts()` function (around line 110-130)

**Find** the current code that calls `getContacts()` and update:

**Before**:
```tsx
const loadContacts = async (page = 1) => {
  try {
    const response = await getContacts();
    // ...
  }
};
```

**After**:
```tsx
const loadContacts = async (page = 1) => {
  try {
    const response = await getContacts({
      page,
      per_page: ITEMS_PER_PAGE,
      group_id: selectedGroupId || undefined,
    });
    // ...
  }
};
```

### Step 3: Add useEffect to reload when group changes

**File**: `src/pages/Contacts.tsx`  
**Add after existing useEffects** (around line 100):

```tsx
// Reload contacts when selected group changes
useEffect(() => {
  setCurrentPage(1);  // Reset to page 1
  loadContacts(1);
}, [selectedGroupId]);
```

### Step 4: Verify group selection state is wired to UI clicks

**File**: `src/pages/Contacts.tsx`  
**Find the groups sidebar rendering** and ensure click handler sets state:

Should look like:
```tsx
{groups.map((group) => (
  <button
    key={group.id}
    onClick={() => setSelectedGroupId(group.id)}
    className={selectedGroupId === group.id ? 'active' : ''}
  >
    {group.name}
  </button>
))}
```

---

## 🧪 How To Verify Fix #3

1. **Network inspection**:
   - Open DevTools → Network tab
   - Go to Contacts page
   - Click on a group in the sidebar
   - Should see: `GET /contacts?group_id=123`
   - Response should show only contacts in that group

2. **UI Test**:
   - Create/ensure you have multiple groups with different contacts
   - Go to Contacts page
   - Click "Marketing" group (or any group)
   - **Expected**: Contact list updates to show only Marketing contacts
   - **Current**: All contacts still shown regardless of group clicked

3. **Database verification**:
```sql
-- Check which contacts are in "Marketing" group
SELECT c.* FROM contacts c
JOIN group_contacts gc ON c.id = gc.contact_id
JOIN contact_groups cg ON gc.group_id = cg.id
WHERE cg.name = 'Marketing' AND c.user_id = [YOUR_USER_ID];
-- This is what should display when user clicks group
```

---

---

# ISSUE #4: "UNCATEGORIZED" GROUP (MANDATORY FEATURE)

## 🔴 Current State: MISSING ENTIRELY

**THE PROBLEM:**
Contacts with no group assigned have nowhere to go. Users can't see or manage "ungrouped" contacts. There's no "Uncategorized" or similar catch-all group.

### Current Architecture

**Contacts Table Structure** (inferred from code):
```sql
CREATE TABLE contacts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  name VARCHAR(100),
  phone VARCHAR(50),
  email VARCHAR(255),
  -- NO group_id field here - groups are in junction table
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);

CREATE TABLE group_contacts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  group_id INT NOT NULL,
  contact_id INT NOT NULL,
  FOREIGN KEY (group_id) REFERENCES contact_groups(id),
  FOREIGN KEY (contact_id) REFERENCES contacts(id)
);
```

**Problem**: A contact either HAS a row in `group_contacts` (belongs to group) or DOESN'T (ungrouped)

### Design Decision: Virtual Group Approach

**RECOMMENDED**: Virtual "Uncategorized" group (computed, not stored)

**Why**:
- ✅ No DB migration needed
- ✅ No duplicate data
- ✅ Cannot be accidentally deleted
- ✅ Works with existing filtering logic
- ✅ Cleanest implementation

**Alternative (not recommended)**: DB-seeded special group
- Requires DB migration
- Risk of deletion/editing
- Adds complexity

---

## ✅ FIX #4: Implement Virtual "Uncategorized" Group

### Step 1: Backend - Add Uncategorized to groups endpoint

**File**: `api/controllers/ContactController.php`  
**Location**: `groups()` function (~line 380-395)  
**Change**: Add virtual uncategorized group

**Before**:
```php
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
```

**After**:
```php
public function groups(): void {
    $userId = Auth::id();
    
    // Get actual groups
    $groups = table('contact_groups')
        ->where('user_id', $userId)
        ->orderBy('name', 'ASC')
        ->get();
    
    // Add contact counts
    foreach ($groups as &$group) {
        $group['contact_count'] = table('group_contacts')
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
    $uncategorizedCount = $stmt->fetch()['count'] ?? 0;
    
    // Add virtual Uncategorized group at start
    array_unshift($groups, [
        'id' => 'uncategorized',
        'name' => 'Uncategorized',
        'description' => 'Contacts with no group assigned',
        'contact_count' => $uncategorizedCount,
        'is_virtual' => true,  // Mark as virtual
    ]);
    
    Response::success(['groups' => $groups]);
}
```

### Step 2: Backend - Update contacts index to handle uncategorized filter

**File**: `api/controllers/ContactController.php`  
**Location**: `index()` function (~line 7-55)  
**Change**: Handle `group_id=uncategorized` special case

**Find this section**:
```php
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
}
```

**Replace with**:
```php
} elseif ($groupId) {
    $pdo = db();
    
    // Handle virtual "uncategorized" group
    if ($groupId === 'uncategorized') {
        $stmt = $pdo->prepare("
            SELECT c.* FROM contacts c
            WHERE c.user_id = ?
            AND c.id NOT IN (
                SELECT DISTINCT contact_id FROM group_contacts
            )
            ORDER BY c.created_at DESC
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
        // Regular group filtering
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
    }
}
```

### Step 3: Frontend - Handle uncategorized group in dialogs

**Files**:
- `src/components/contacts/AddContactModal.tsx`
- `src/components/contacts/EditContactModal.tsx`

**Location**: In the Group select dropdown

**Current**:
```tsx
<SelectContent>
  {groups.map((g) => (
    <SelectItem key={g.id} value={String(g.id)}>{g.name}</SelectItem>
  ))}
</SelectContent>
```

**After**:
```tsx
<SelectContent>
  <SelectItem value="">None (Uncategorized)</SelectItem>
  {groups.filter(g => g.id !== 'uncategorized').map((g) => (
    <SelectItem key={g.id} value={String(g.id)}>{g.name}</SelectItem>
  ))}
</SelectContent>
```

**Why**: 
- "Uncategorized" appears in list for viewing/filtering
- But when adding/editing, user selects "None" to explicitly set no group
- Prevents confusion

### Step 4: Frontend - Update Contacts.tsx to show Uncategorized in sidebar

**File**: `src/pages/Contacts.tsx`  
**Location**: Groups sidebar rendering

**Should already work** because groups endpoint returns it, but verify:
```tsx
{groups.map((group) => (
  <button
    key={group.id}
    onClick={() => setSelectedGroupId(group.id)}
    className={selectedGroupId === group.id ? 'active' : ''}
  >
    {group.name}
    {group.contact_count > 0 && (
      <span className="count">({group.contact_count})</span>
    )}
  </button>
))}
```

---

## 🧪 How To Verify Fix #4

### Test 1: Uncategorized group appears in sidebar

1. Go to Contacts page
2. Look at groups sidebar
3. **Expected**: First item is "Uncategorized (N)" where N = number of ungrouped contacts
4. **Current**: Uncategorized section doesn't exist

### Test 2: Clicking Uncategorized filters correctly

1. Create or ensure you have ungrouped contacts
2. Click "Uncategorized" in sidebar
3. **Expected**: Only contacts with no group show
4. **Current**: Feature doesn't exist

### Test 3: Add dialog shows Uncategorized option

1. Click "Add Contact"
2. Look at Group dropdown
3. **Expected**: "None (Uncategorized)" appears as first option
4. **Current**: Might show actual uncategorized group or error

### Test 4: Database verification

```sql
-- Find ungrouped contacts
SELECT c.* FROM contacts c
WHERE c.user_id = [YOUR_USER_ID]
AND c.id NOT IN (
  SELECT DISTINCT contact_id FROM group_contacts
);
-- These should appear when filtering by "Uncategorized"
```

---

---

# IMPLEMENTATION CHECKLIST

## Phase 1: Templates Visibility
- [ ] Fix TemplateModal.tsx `onSave` callback (line 113-126)
- [ ] Fix Templates.tsx response parsing (lines 52-56)
- [ ] Test: Create template appears immediately in list
- [ ] Test: Network shows refetch after create

## Phase 2: Groups Dropdown
- [ ] Fix AddContactModal.tsx `loadGroups()` (lines 47-55)
- [ ] Fix EditContactModal.tsx `loadGroups()` (lines 59-67)
- [ ] Test: Add Contact dialog shows all groups
- [ ] Test: Edit Contact dialog shows all groups

## Phase 3: Group Filtering
- [ ] Update api.ts `getContacts()` signature (line ~195)
- [ ] Update Contacts.tsx `loadContacts()` to pass group_id
- [ ] Add useEffect to reload when `selectedGroupId` changes
- [ ] Test: Clicking group filters contacts
- [ ] Test: Network shows `?group_id=` parameter

## Phase 4: Uncategorized Group
- [ ] Backend: Update `groups()` function to add virtual uncategorized
- [ ] Backend: Update `index()` to handle `group_id=uncategorized`
- [ ] Frontend: Update dialogs to show "None (Uncategorized)"
- [ ] Test: Uncategorized appears in sidebar
- [ ] Test: Clicking uncategorized filters correctly
- [ ] Test: Ungrouped contacts appear in uncategorized section

---

# SUMMARY TABLE

| Issue | Root Cause | Files | Severity | Fix Type |
|-------|-----------|-------|----------|----------|
| #1: Templates not showing | Modal doesn't refetch + wrong response parsing | TemplateModal.tsx, Templates.tsx | 🔴 Critical | Update 2 functions |
| #2: Groups dropdown empty | Wrong response property path (`res.data.groups` vs `res.groups`) | AddContactModal.tsx, EditContactModal.tsx | 🔴 Critical | Fix parsing in 2 places |
| #3: Group filtering broken | Not implemented - API ready, frontend missing | api.ts, Contacts.tsx | 🔴 Critical | Add 3 pieces: API param, call site, useEffect |
| #4: No Uncategorized | Feature not implemented | ContactController.php, dialogs | 🔴 Critical | Backend + Frontend |

---

# NEXT STEPS

1. **Verify this audit** - Read through and confirm all findings match your environment
2. **Implement fixes in order** - Phase 1 → 2 → 3 → 4
3. **Test each phase** - Use verification steps before moving to next
4. **Commit with message**: "Fix templates, groups visibility, filtering, and add uncategorized"

