# Role and permission matrix

| Capability | Public | User | Admin | System/provider |
|---|---:|---:|---:|---:|
| Landing/contact form | allow | allow | allow | deny |
| Own contacts/templates/campaigns/wallet | deny | allow own | allow own where applicable | deny |
| Another tenant's records | deny | deny | deny unless explicit support tool | deny |
| `/guymhan/*` UI and `/admin/*` API | deny | deny | allow | deny |
| LogicSMS/PayOS webhook | signed/secret request only | deny | deny | allow verified |
| Cron worker | deny | deny | deny | cron secret/CLI only |
| Email campaign mutation | deny | deny (410) | deny (410) | deny |

Live negative-role and cross-tenant tests remain mandatory before publication.

