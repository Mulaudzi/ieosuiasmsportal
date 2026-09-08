# Architecture

The portal is a React/Vite SPA backed by a raw-PHP JSON API and MySQL. `user_id` is the MVP tenant boundary. Authentication uses versioned HS256 bearer tokens and the canonical role is `users.role` (`user` or `admin`).

SMS business rules live under `api/domain`; provider adapters implement `SmsProvider`. Campaign HTTP requests persist recipient work only. `api/bin/sms-worker.php` claims durable database rows and contacts the provider outside the browser request. `api/bin/sms-scheduler.php` moves due campaigns into the same queue.

The canonical MVP SMS tables use the `sms_` prefix so existing installations can migrate additively while legacy campaign/email data remains available. New SMS development must use the v2 endpoints and tables.
