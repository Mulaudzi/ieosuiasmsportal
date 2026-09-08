# Security rotation required

Operational configuration and a database diagnostic file were committed before this reconstruction. Do not reuse any value from repository history.

- Rotate the database user password and restrict its network/table privileges.
- Generate a new high-entropy `JWT_SECRET`; increment user auth versions after rollout.
- Rotate/replace any admin bootstrap key and leave bootstrap disabled after provisioning.
- Rotate LogicSMS credentials if that provider account was populated.
- Rotate PayOS public/secret/callback credentials.
- Rotate SMTP credentials.
- Rotate the Google OAuth client secret.
- Rotate the legacy DLR webhook secret.
- Review EFT/banking values for unwanted disclosure even though they are not authentication secrets.

Consider Git-history remediation with repository administrators after rotations. History was not rewritten automatically. Purge credential-bearing files from deployed web roots and caches.
