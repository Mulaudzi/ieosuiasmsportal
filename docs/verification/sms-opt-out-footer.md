# SMS opt-out footer

- Apply `api/migrations/009_sms_opt_out_preferences.sql` before releasing the API.
- Settings > Compliance now saves the footer in `sms_preferences`, scoped to the authenticated user.
- New campaigns enable the footer by default. The message and final send steps expose the switch.
- Preview and creation use the same server composition, personalization and segment calculations.
- The completed message text is persisted to campaign/message records; subsequent settings changes do not alter scheduled sends or retries.
- Legacy API callers omitting the switch retain their existing behavior. Existing campaigns are unchanged.
- This does not implement inbound STOP processing or change suppression of opted-out recipients.

Tests: save and reload a custom footer; toggle on/off; check the full preview; check a 155-character GSM message grows from one to two segments with the default footer; check Unicode footer calculation; queue one controlled SMS and verify the received text. Test scheduled delivery separately. Do not resend existing campaigns merely to test this feature.
