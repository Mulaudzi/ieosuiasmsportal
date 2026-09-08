# Environment contract

`api/.env.example` is the names-only backend contract; root `.env.example` contains frontend build variables. Production must fail closed when JWT, provider verification, pricing, payment signing, or database configuration is absent.

Do not commit `.env` files. LogicSMS credentials must be supplied only through the deployment environment.

Manual EFT requires `EFT_BANK_NAME`, `EFT_ACCOUNT_NAME`, `EFT_ACCOUNT_NUMBER`, and `EFT_BRANCH_CODE`. The endpoint fails closed when any are missing; the repository contains no placeholder banking details.

`LOGICSMS_CA_BUNDLE` is optional on hosts whose PHP/cURL already has a trusted CA store. On Windows or minimal containers, set it to a readable CA bundle path. TLS verification must never be disabled.
