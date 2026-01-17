# CSV IMPORT GUIDE & USER GUIDANCE

## Current State
The CSV import modal currently has:
- ✅ File upload with drag-and-drop
- ✅ Column mapping UI
- ✅ Preview table showing first 10 rows
- ✅ Validation: requires phone OR email
- ✅ Group column mapping

## What's Missing (Issues #4, #6)

### Issue: App Goes Blank During Import
**Problem**: When user imports a large file, no feedback is provided.  
**Solution**: Improve error handling and show more detailed progress.

### Issue: No Phone Format Guidance
**Problem**: Users don't know what phone format to use.  
**Solution**: Add help text and validation messaging.

### Issue: No CSV Format Examples
**Problem**: Users don't know what their CSV should look like.  
**Solution**: Add format specification and examples.

---

## Implementation: Phone Format Validation

### What Phone Formats Are Accepted?
All phone numbers should be in E.164 format (international):
- ✅ `+27123456789` (South Africa)
- ✅ `+1234567890` (USA)
- ✅ `+441234567890` (UK)
- ❌ `0123456789` (No country code)
- ❌ `(123) 456-7890` (Local format)

### Validation Rules
1. Must start with `+` (plus sign)
2. Must contain only digits after the +
3. Minimum 10 digits, maximum 15 digits (E.164 standard)

### Backend Validation (Already in place)
```php
// ContactController.php - store() function (lines 117-125)
if ($phone) {
    // Remove any non-numeric characters except + at start
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    // Ensure it starts with + for E.164 format
    if ($phone && $phone[0] !== '+' && !empty($data['country_code'])) {
        $phone = $data['country_code'] . ltrim($phone, '0');
    }
}
```

---

## Recommended CSV Format

### Minimum Required Columns
```
phone,name,email
+27123456789,John Doe,john@example.com
+27987654321,Jane Smith,jane@example.com
+27555111111,Bob Johnson,bob@example.com
```

### Full Format (With Groups)
```
name,phone,email,group
John Doe,+27123456789,john@example.com,Sales
Jane Smith,+27987654321,jane@example.com,Marketing
Bob Johnson,+27555111111,bob@example.com,Sales
```

### Alternative Column Names (Auto-detected)
- **Name**: `name`, `first_name`, `firstname`
- **Phone**: `phone`, `mobile`, `cell`
- **Email**: `email`, `email_address`
- **Group**: `group`, `category`, `list`

### File Size Limits
- **Recommended**: Up to 5,000 rows
- **Maximum**: 50,000 rows (may take 30+ seconds)
- **Suggested**: For >10,000 rows, split into multiple files

---

## Import Modal Flow

```
Step 1: UPLOAD
  ├─ Drag & drop CSV file
  ├─ Or click to browse
  └─ Validates file is .csv

Step 2: MAPPING
  ├─ Auto-detects column names
  ├─ User can manually override
  ├─ Validates at least phone OR email selected
  └─ Shows file info: name, row count

Step 3: PREVIEW
  ├─ Shows first 10 rows in table
  ├─ Counts valid contacts (has phone OR email)
  ├─ Shows invalid count (missing both phone & email)
  ├─ Shows group assignments
  └─ Ready to import

Step 4: IMPORTING
  ├─ Progress indicator
  ├─ Shows "Importing..." message
  ├─ API call in progress
  └─ Shows results on completion
```

---

## User Guidance: What to Expect

### Before Import
1. **Prepare your CSV file**
   - Save as UTF-8 encoding
   - Include headers in first row
   - Use format: `name,phone,email,group` OR `phone,email,name`

2. **Check phone numbers**
   - All numbers must have country code
   - Format: `+XXYYYYYYYYYY` (+ followed by digits)
   - Examples: `+27123456789`, `+1234567890`

3. **Test with small batch first**
   - Try 10-50 contacts before bulk import
   - Verify group assignments work
   - Check if any contacts failed

### During Import
- Don't close the modal
- Don't navigate away
- Wait for success message

### After Import
- New contacts appear in Contacts list
- Groups are automatically created if they don't exist
- Duplicates are skipped (if option enabled)
- Review results message

---

## Common Issues & Fixes

### ❌ Error: "Invalid group ID"
**Cause**: Group column contains group names, not IDs  
**Solution**: Use group names, system auto-creates groups

### ❌ Error: "Phone format invalid"
**Cause**: Phone missing country code  
**Solution**: Add `+` and country code, e.g., `+27123456789`

### ❌ Import appears to work but contacts don't appear
**Cause**: All rows invalid (missing phone AND email)  
**Solution**: Check preview - must have green "valid contacts" count

### ❌ Only some contacts imported
**Cause**: Duplicate phone numbers (skip duplicates enabled)  
**Solution**: Either allow duplicates or clean CSV first

---

## Tips for Excel/Sheets Users

### If exporting from Excel:
1. File → Save As
2. Format: CSV (.csv)
3. Encoding: UTF-8
4. First row = headers

### If using Google Sheets:
1. File → Download → Comma Separated Values (.csv)
2. Upload to import

### Column Order (Either works):
```
Option A: name, phone, email, group
Option B: phone, email, name
Option C: Any order - system auto-detects
```

---

## Success Examples

### Simple Import (Phone + Name)
```
phone,name
+27123456789,John Doe
+27987654321,Jane Smith
```
✅ Result: 2 contacts created, no groups

### With Groups
```
name,phone,email,group
John Doe,+27123456789,john@example.com,Sales
Jane Smith,+27987654321,jane@example.com,Marketing
```
✅ Result: 2 contacts created, 2 groups auto-created

### Batch Update (Add to existing group)
```
email,phone,group
john@example.com,+27123456789,VIP
jane@example.com,+27987654321,VIP
```
✅ Result: Existing contacts moved to VIP group

