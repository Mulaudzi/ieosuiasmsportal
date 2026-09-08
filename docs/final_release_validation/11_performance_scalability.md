# Performance and scalability

| Concern | Existing mitigation | Remaining validation |
|---|---|---|
| Large recipient lists | server limit, normalized deduplication, durable message rows | 10k-recipient memory/time test |
| Dispatch overlap | durable states and worker batches | concurrent worker claim test |
| Wallet races | transaction and row locking | concurrent reservation/callback test |
| Contact lists | pagination and bounded API parameters | 10k/50k UI/API test |
| Reports | date ranges | query plans/index verification on production-like data |
| Webhook storms | persistent events/rate controls | replay/burst test |
| Frontend bundle | Vite chunking | production build size and lazy-loading review |

