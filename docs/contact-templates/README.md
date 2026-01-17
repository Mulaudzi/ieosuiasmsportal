# Contact CSV Templates

This folder contains sample CSV templates for importing contacts into the system.

## Column Order & Requirements

The CSV import requires the following columns **in this specific order**:

| Column | Required | Notes |
|--------|----------|-------|
| `name` | Yes | First name (required, defaults to "Esteemed" if empty) |
| `surname` | No | Last name |
| `phone` | At least one | Phone number (accepts variations like `mobile`, `cell`, `telephone`) |
| `email` | At least one | Email address |
| `country_code` | No | Country code (defaults to `+27` if not specified) |
| `status` | No | Contact status (typically `subscribed` or `unsubscribed`) |

## CSV Format Rules

1. **Column Names**: Header row must contain column names (case-insensitive, whitespace trimmed)
2. **Phone Format**: Can include hyphens, spaces, or parentheses - system will clean automatically
3. **Country Code**: Should start with `+` (e.g., `+27`, `+1`, `+44`)
4. **Status**: Defaults to `subscribed` if not specified
5. **Duplicates**: By default, duplicates based on phone number are skipped

## Column Name Variations

The system accepts common variations:
- `name` = `first_name` = `firstname`
- `surname` = `last_name` = `lastname`
- `phone` = `mobile` = `cell` = `telephone`
- `email` = `e-mail`
- `country_code` = `country`

## Sample File

See `sample-contacts.csv` for a complete example with 10 sample contacts.

## Import Process

1. Go to Contacts → Import Contacts
2. Select your CSV file
3. Optionally assign to a contact group
4. Click Import
5. Review the import summary (imported count, failed, duplicates skipped)

## Requirements for Successful Import

- At least one contact per row must have either a **phone number** OR **email**
- Column count must match header count (no extra or missing columns)
- File formats supported: `.csv`, `.txt`, `.xlsx`, `.xls`

## Example CSV

```csv
name,surname,phone,email,country_code,status
John,Smith,+27-123-456789,john@example.com,+27,subscribed
Sarah,Johnson,+27 98 765 4321,sarah@example.com,+27,subscribed
Michael,Williams,+27(555)1234-56,michael@example.com,+27,subscribed
```

## Error Handling

- **Rows with no phone or email**: Skipped (counted as failed)
- **Column count mismatch**: Entire row skipped
- **Duplicate phone numbers**: Skipped if "skip duplicates" option is enabled

## Tips

- Always include a header row with column names
- Use consistent country codes throughout the file
- Phone numbers don't need to be formatted - system cleans them automatically
- Test with a small sample first before importing large lists
