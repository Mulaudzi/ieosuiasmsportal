# PRODUCTION ISSUES - DETAILED ANALYSIS

## ISSUE #1: CONTACT IMPORT 500 ERROR

### Root Cause Found ❌
**File**: `api/controllers/ContactController.php` line 292-415
**Function**: `import()`

**Problem**: 
- Line 328: `$handle = fopen($file['tmp_name'], 'r')` - **NO ERROR HANDLING**
- If file cannot be opened, fopen returns FALSE
- Line 331: `$header = fgetcsv($handle)` - Passes FALSE to fgetcsv
- Line 332: `$header = array_map('strtolower', array_map('trim', $header))` - If fgetcsv returns NULL/FALSE, array_map() still works BUT
- **The real issue**: Entire function lacks try/catch wrapper
- When ANY exception occurs (DB insert, file ops), PHP throws 500 with no meaningful error response
- Frontend receives "Server error. Please try again later."

**Why 500 instead of 400**:
- Request::file() might fail
- fopen() might fail
- DB transaction might fail
- But these are NOT wrapped in try/catch with proper Response::error()

### ISSUE #2: CONTACT COUNT SHOWS "0031" 

**Root Cause Found ❌**
**File**: `src/pages/Contacts.tsx` line 127-130
**Problem**: Already fixed in previous edits but let me verify...
Line 127: `total: parseInt(String(contactsRes.meta?.total ?? contactsData.length), 10)`

This SHOULD work. The issue might be:
1. contactsRes structure is different than expected
2. Meta is nested differently
3. API returns something other than expected

**Need to check**: What does the API actually return?

### ISSUE #3: TEMPLATE DELETE DOESN'T REFRESH

**Status**: ✅ Already fixed!
**File**: `src/pages/Templates.tsx` line 119
- Has optimistic state update: `setTemplates(templates.filter(t => String(t.id) !== String(templateToDelete.id)))`
- This should work perfectly

**Could fail if**: 
- Templates have different data types (number vs string)
- But code handles it with `String()` coercion

### ISSUE #4: NAME VALIDATION (NAME + SURNAME)

**Root Cause Found ❌**
**File**: `api/controllers/ContactController.php` line 347-348
```php
$name = $data['name'] ?? $data['first_name'] ?? $data['firstname'] ?? 'Esteemed';
$surname = $data['surname'] ?? $data['last_name'] ?? $data['lastname'] ?? null;
```

**Problem**:
- Name has fallback to 'Esteemed' - **WRONG**
- Surname can be NULL - **WRONG**
- No validation that BOTH name AND surname are required
- No error returned to user

### ISSUE #5: CSV FORMAT INSTRUCTIONS MISSING

**Status**: ❌ Not implemented
**File**: `src/components/contacts/ContactImportModal.tsx`
- Upload step shows generic message
- No CSV format instructions
- No inline validation
- No format rules displayed

---

## FIXES NEEDED (in order)

1. ✅ Add try/catch wrapper to import() with proper error responses
2. ✅ Validate name + surname are both provided
3. ✅ Add CSV format instructions to upload step
4. ✅ Add inline validation in mapping step
5. ✅ Verify API response structure for count

---

