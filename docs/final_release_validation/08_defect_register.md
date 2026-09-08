# Defect register

| ID | Severity | Defect | Action/result |
|---|---|---|---|
| DEF-001 | P1 | Customer UI framed wallet money as credits inconsistently | Fixed: credits are billable segments; ZAR remains internal accounting source |
| DEF-002 | P1 | Insufficient balance gave no way to reduce current send | Fixed: individual exclusion and automatic fit; no contact/group mutation |
| DEF-003 | P1 | Email campaign UI/API implied an available product | Fixed: visible Coming Soon page and API mutation/read routes return 410 |
| DEF-004 | P1 | Wallet hooks read only one API envelope shape | Fixed: accept canonical top-level response |
| DEF-005 | P1 | Prior PayOS return did not visibly credit balance | Open: controlled signed-callback end-to-end evidence required |
| DEF-006 | P1 | Production role/admin regressions were previously observed | Open: live admin/user negative authorization suite required |
| DEF-007 | P2 | OneDrive placeholder files block local build tools inside restricted execution | Workaround: hydrated/approved build environment; not an application defect |
| DEF-008 | P2 | Reports mixed legacy and canonical SMS stores | Fixed: customer report statistics, charts, comparison and exports use canonical SMS tables |
| DEF-009 | P1 | Payment email failure could relabel a committed wallet credit as failed | Fixed: notification runs outside the financial transaction failure path |
| DEF-010 | P1 | Production dependency audit reported critical/high findings | Fixed: clean lockfile regenerated with compatible patched packages; 2 moderate React Router 6 findings remain with SSR absent and navigation targets internally constructed |
| DEF-011 | P1 | Admin dashboard returned before later hooks and linked denied users to customer login | Fixed: hooks are unconditional and denial points to `/guymhan/login` |
| DEF-012 | P1 | Payment return could claim success without a reference/callback | Fixed: only API status `completed` produces success wording |
