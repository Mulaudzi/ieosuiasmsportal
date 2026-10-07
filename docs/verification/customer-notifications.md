# Customer notification wiring

Implemented locally, not deployed or activated.

The bell reconciles committed campaign completion and successful credit purchases when fetching notifications. Events from the last 30 days are eligible; older history is not imported. Each poll reconciles at most 100 campaigns and 100 payments. No SMS, payment, wallet, email or delivery-state transitions are performed by this service. Polling runs every 30 seconds while visible, and refreshes on opening, browser focus and reconnection. This is in-app notification polling, not browser push or email delivery.

Apply `api/migrations/013_notification_events.sql` before activation, after backing up and checking the production schema. The existing `notifications` table must support the controller's existing columns (`user_id`, `type`, `title`, `message`, `data`, `read`, `read_at`, `created_at`, `updated_at`) and the `campaign_complete` and `payment_complete` types. Verify `type` is not a restrictive enum before deployment; do not alter it without reviewing the live schema. The new event ledger uses transactional InnoDB storage; confirm the existing notifications table is also transactional.

Event claims and notification insertion commit together. The ledger is retained when an alert is dismissed so it cannot be recreated on the next poll. Existing admin notifications are retained. Missing migration or reconciliation errors produce a visible warning while still allowing existing notifications to be read. Campaign notifications describe sending completion, not guaranteed delivery. Payment notifications use the stored credited amount and never rerun a payment.

Verification: isolated SQLite tests exercise source-record ownership, pending/old event exclusion, duplicate suppression, dismissed-event persistence and atomic rollback. Production MySQL/schema verification and real account acceptance remain required. The browser test uses mocked private APIs and checks mobile pagination, mark-all-read, dismissal, campaign navigation, error feedback and popover width. No real SMS, payment or email is created by these tests.

Acceptance after deployment: open the bell, verify recent campaign/purchase alerts belong to the signed-in account, open a campaign alert, mark read and refresh, dismiss and refresh, browse older pages, and verify the unread count. Switching accounts must not reveal the previous account's alerts. Low-credit generation and OS/browser push are not added by this patch.
