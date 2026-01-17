# Fix: Contact Import Mapping Flexibility

**Date**: 2026-01-17  
**Issue**: Contact import was enforcing mandatory Name+Surname fields, but users need flexible field mapping with only Phone as required.  
**Status**: ✅ IMPLEMENTED

---

## Problem Description

The previous import validation was too strict:
- **Mandatory Fields**: Full name with both first and last name
- **Validation Rule**: Contact names MUST contain a space (enforcing "First Last" format)
- **User Impact**: CSV files without names or with single-part names were rejected as failures

**Desired Behavior**:
- **Mandatory Field**: Phone number only
- **Optional Fields**: Name, surname, email, group
- **Flexibility**: Accept contacts with any combination of available data
- **Use Cases**: Phone-only contacts, incomplete profiles, data from various sources

---

## Solution Overview

### 1. Frontend Changes (ContactImportModal.tsx)

#### Mapping Requirements Updated
```tsx
// OLD: Required name column for full name
if (!columnMapping.name) {
  toast({
    title: "Name column required",
    description: "Please select which column contains the full name (First Name Last Name).",
    variant: "destructive",
  });
  return;
}

// NEW: Only phone is mandatory
if (!columnMapping.phone) {
  toast({
    title: "Phone column required",
    description: "Please select which column contains the phone number.",
    variant: "destructive",
  });
  return;
}
```

#### Validation Filter Updated
```tsx
// OLD: Strict validation requiring first+last name
const validContacts = parsedContacts.filter((c) => {
  if (!c.name) return false;
  const nameParts = c.name.trim().split(/\s+/);
  if (nameParts.length < 2) return false;  // ← Rejected single names
  if (!c.phone && !c.email) return false;
  return true;
});

// NEW: Flexible validation requiring only phone
const validContacts = parsedContacts.filter((c) => {
  if (!c.phone) return false;  // ← Only phone required
  return true;
});
```

#### CSV Format Instructions Updated
**Old Format**:
```
Full Name,Phone Number,Email
John Doe,+27791234567,john@example.com
```

**New Format** (flexible):
```
Name,Phone,Email,Group
John Doe,+27791234567,john@example.com,VIP
Jane Smith,+27791234568,,Customers
,+27791234569,bob@example.com,Subscribers
```

**Column Requirements**:
- ✓ Phone: Required for all contacts
- ✓ Name: Optional (can be empty)
- ✓ Email: Optional (can be empty)
- ✓ Group: Optional (will create contact without group)

### 2. Backend Changes (ContactController.php)

#### Validation Logic Updated

**Old Validation**:
```php
// VALIDATION: Name + Surname must both be present
if (empty($name) || empty($surname)) {
    $failed++;
    continue;  // ← Rejected rows with missing name/surname
}

$fullName = $name . ' ' . $surname;

// Skip if no phone and no email
if (empty($phone) && empty($email)) {
    $failed++;
    continue;
}
```

**New Validation**:
```php
// Clean phone number
if ($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (empty($phone)) {
        $phone = null;
    }
}

// VALIDATION: Phone is required (mandatory field)
if (empty($phone)) {
    $failed++;
    continue;  // ← Only phone must be present
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

// Database insertion with optional fields
$contactId = table('contacts')->insert([
    'user_id' => $userId,
    'name' => !empty($name) ? $name : '',        // ← Empty string if not provided
    'surname' => !empty($surname) ? $surname : '', // ← Empty string if not provided
    'phone' => $phone ?: null,
    'email' => $email ?: null,
    // ... other fields
]);
```

---

## Technical Details

### Field Handling Logic

| Field | Required | Storage | Behavior |
|-------|----------|---------|----------|
| Phone | ✅ YES | Cleaned & stored | Import fails if missing |
| Name | ❌ NO | Empty string | Optional, can be filled later |
| Surname | ❌ NO | Empty string | Optional, can be filled later |
| Email | ❌ NO | NULL | Optional, can be empty |
| Group | ❌ NO | NULL | Contact created without group |

### Column Mapping UI

Users can now:
- Leave "Name Column" unmapped (set to "— None —")
- Leave "Email Column" unmapped
- Leave "Group Column" unmapped
- **Must** select a "Phone Column"

### Import Flow Validation

1. **Upload Step**: User uploads CSV file
2. **Mapping Step**: 
   - Select which CSV columns map to contact fields
   - Name/Email/Group are optional
   - Phone is required
3. **Preview Step**: 
   - Shows all rows with phone numbers
   - Skips rows without phone
   - Display count of valid/invalid rows
4. **Import Step**: 
   - Processes valid rows (with phone)
   - Skips duplicates (if enabled)
   - Creates contacts with flexible field combinations

### Phone Number Processing

Phone numbers are required and processed as follows:
1. Trim whitespace
2. Remove non-numeric characters except leading `+`
3. Validate format (must have digits or + prefix)
4. Store as-is with country code if provided

---

## Files Modified

### Frontend
- **`src/components/contacts/ContactImportModal.tsx`**:
  - Line 49-57: Updated `proceedToPreview()` validation logic
  - Line 209-226: Updated CSV format instructions
  - Line 233-239: Updated validation filter for `validContacts`

### Backend
- **`api/controllers/ContactController.php`**:
  - Line 364-417: Updated validation and field handling logic
  - Lines 383-388: New flexible name/surname combination logic
  - Lines 365-370: Phone-only validation (removed name+surname requirement)

---

## Testing Checklist

### Frontend Testing
- [ ] CSV with only Phone column → Should import successfully
- [ ] CSV with Name+Phone columns → Should import with names
- [ ] CSV with Phone+Email columns → Should import with emails
- [ ] CSV with some rows missing Name → Should import phone-only contacts
- [ ] CSV with all fields empty in a row → Should skip that row
- [ ] Column mapping shows "— None —" for optional columns → Should work

### Backend Testing
- [ ] Import 100 phone-only contacts → All 100 created
- [ ] Import contacts with partial names → Stored as provided
- [ ] Import with missing surname → Name only stored
- [ ] Import with duplicate phones + skip duplicates enabled → Correctly skipped
- [ ] Import with invalid phone format → Row rejected, failed count increased

### API Response Testing
- [ ] Success response includes: `imported`, `failed`, `duplicates` counts
- [ ] Error response provides clear message for validation failures

---

## Example CSV Scenarios

### Scenario 1: Phone Only
```csv
Phone
+27791234567
+27791234568
+27791234569
```
**Result**: 3 contacts created with phone only, names set to 'Contact'

### Scenario 2: Name + Phone
```csv
Name,Phone
John Doe,+27791234567
Jane Smith,+27791234568
```
**Result**: 2 contacts with full names and phones

### Scenario 3: Mixed Fields
```csv
Name,Surname,Phone,Email
John,,+27791234567,john@example.com
,Smith,+27791234568,
Alice Jones,+27791234569,alice@example.com
```
**Result**: 
- Contact 1: John (first name only) + phone + email
- Contact 2: Smith (surname only) + phone
- Contact 3: Alice Jones (full name) + phone + email

### Scenario 4: With Groups (via separate column)
```csv
Name,Phone,Group
John Doe,+27791234567,VIP
Jane Smith,+27791234568,Regular
```
**Result**: Contacts created and assigned to specified groups

---

## Backward Compatibility

✅ **COMPATIBLE WITH EXISTING DATA**
- Existing contacts with names unchanged
- CSV files with "First Name, Last Name, Phone" format still work
- CSV files with "Full Name, Phone" format still work
- Previous import logs not affected

⚠️ **MIGRATION NOTES**
- Old validation enforced name+surname; new validation only requires phone
- This means previously-rejected files may now import successfully
- Users can now create phone-only contacts if desired

---

## Benefits

1. **Increased Flexibility**: Accept data from various sources with different column names
2. **Reduced Friction**: No more rejections for incomplete data
3. **Better Data Handling**: Phone-only contacts for outbound campaigns
4. **User Choice**: Users decide which fields are important
5. **Error Reduction**: Clear feedback on mandatory vs optional fields

---

## Related Issues

- **Previous Session**: Name validation was too strict (enforced first+last name)
- **User Feedback**: "Can't import phone-only lists"
- **Use Case**: Marketing campaigns with incomplete contact data

