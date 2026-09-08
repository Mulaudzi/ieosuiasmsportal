# Administrator operations console

`/guymhan` is the canonical production operations console. The former `/admin` frontend routes are intentionally not registered. This non-obvious route reduces casual probing but does not replace authentication: every operation continues to require a signed active administrator identity on the API.

The overview separates cash received (`payments`) from wallet liability (`wallets`) and credit movement (`wallet_ledger`). Customer drill-down mirrors wallet balances, contacts, groups, templates, opt-outs, campaigns, payments and recent ledger entries. Campaign drill-down exposes per-recipient provider references, attempts, terminal timestamps and failure reasons.

The LogicSMS card queries `querycredits.aspx` from the server. `LOGICSMS_LOW_CREDIT_THRESHOLD` defaults to 100. A provider or TLS failure is shown as unavailable and must never be converted into a zero balance.

Service cards use `service_heartbeats`, not the retired `cron_jobs` bookkeeping table. The support, SMTP, notification and audit interfaces remain at `/guymhan/tools`. Downloads use bearer-authenticated requests; tokens must not be placed in query strings.

On narrow screens the console uses a compact sticky app bar and slide-out navigation drawer. Content spacing, dialogs, action controls and data tables are responsive and touch-friendly; wide datasets scroll inside their own containers instead of widening the viewport.
