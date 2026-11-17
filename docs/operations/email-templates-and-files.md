# Email templates, bounded files and content review

Customer workspace: `https://sms.ieosuia.com/email-campaigns`.
Independent administrator review: `/guymhan/email-campaigns`.

The customer workspace offers Business letter, Announcement, Newsletter and Showcase layouts. Customers can change accent/background/text colours, add a button with a full public HTTPS destination, choose a scanned logo and campaign image, and switch between desktop/mobile layout previews. Up to 20 owned brand designs can be saved and updated. Saving or previewing a design neither approves a campaign nor sends email.

The sender identity, business postal address, verified Reply-To, unsubscribe link and abuse-report link remain controlled by the service. Customers cannot supply raw HTML, arbitrary headers, remote tracking images or an arbitrary From address. Text is escaped by the server renderer; unreadable body text colours are rejected. Logo and campaign images are included as MIME inline images rather than external trackers.

The verified account inbox is the default Reply-To. Additional reply inboxes require their own code verification and an updated sender review; see [email-reply-inbox-verification.md](email-reply-inbox-verification.md).

## Initial limits

| Control | Limit |
| --- | --- |
| Ordinary attachments | 3 per campaign |
| Each attachment | 2 MiB |
| All ordinary attachments combined | 5 MiB |
| Logo | 256 KiB, PNG/JPEG |
| Campaign image | 512 KiB, PNG/JPEG |
| Complete raw MIME message | 8 MiB |
| Attached/inline-file campaign delivery estimate | 100 MiB across all recipients |
| Total service delivery data | 500 MiB per rolling 24 hours |
| Reserved upload bytes per account | 25 MiB per UTC day |
| Reserved upload bytes across the service | 250 MiB per UTC day |
| Active file references per account | 50 |
| Saved brand designs per account | 20 |

Upload tickets consume the upload allowance even when unused. Their signed PUT URL expires after 5 minutes, fixes the content length, MIME type and SHA-256 checksum, and requires `If-None-Match: *`. The bucket enforces conditional writes. Reusing a ticket cannot create another object version. Multipart uploads and overwrite/delete privileges are not exposed to customers. Both frontend and backend reject oversized metadata before file content is read or an upload is authorized.

PDF, PNG and JPEG are the only initial file types. Archives, Office documents, SVG and executable files are excluded. Images must be static, at most 2400 pixels in each dimension and at most 4 million pixels. Images are decoded and re-encoded without metadata or trailing payloads. PDFs must be unencrypted, at most 30 pages and within bounded parsing/decompression limits. Scripts, forms, embedded files and dangerous document actions are rejected. Links must be public HTTPS destinations of bounded length.

## Upload and review flow

1. The authenticated portal obtains a bounded, owned upload ticket from AWS. File bytes go directly to a private S3 quarantine prefix; Axxess does not process bulk file uploads or mass-mail delivery.
2. The dedicated GuardDuty protection plan scans that exact S3 object version. An EventBridge rule accepts only results from the expected account, region, bucket and protection plan.
3. Only `COMPLETED` plus `NO_THREATS_FOUND` can proceed to file-format/content inspection. Threats, failed scans, unsupported content and inspection failures cannot be selected for sending. Unknown or undelivered results remain quarantined.
4. Validated bytes are written once to a separate private clean prefix. Download links last 5 minutes and name the exact clean version. A campaign snapshot pins file IDs, versions, sizes and checksums.
5. Subject, body, button destination, PDF links and bounded extracted PDF text receive explainable risk checks. Concealed text, certain requests for passwords/OTP/PIN/recovery secrets, shortened destinations and unsafe links are blocked. Pressure language, financial-return promises, excessive subject styling and other signals require review.
6. Every campaign still requires independent sender, recipient-consent, content and destination review. File-bearing campaigns additionally require an explicit visual-file acknowledgement and acknowledgement of risk findings. The reviewer identity, time, evidence and acknowledgements are retained with the campaign.
7. Queueing and dispatch recheck file eligibility. The worker checks pacing, service/provider allowances and estimated delivery bytes before fetching attachments, then checks actual MIME size and charges only under the existing durable acceptance/accounting protocol.

A malware scan or a rule-based content score is not proof that a message is legitimate, wanted or safe. Images are not OCR-scanned. Reviewers must inspect images and PDF content; extracted text can be incomplete. No inbox-placement or complete spam-detection guarantee is made.

## Storage and operation

The private file bucket is encrypted, versioned, blocks public access and allows browser uploads only from `https://sms.ieosuia.com`. Quarantine objects are scheduled for expiry after 7 days; clean objects after 90 days. Runtime eligibility expires independently of asynchronous lifecycle removal. Campaign and accounting evidence have separate retention requirements. The themed privacy policy describes this processing.

The dedicated file scanner has no SES-send, portal-secret or accounting-secret permission. The API can reserve quarantine writes and sign clean-version downloads. The worker can read clean versions. Only the existing worker mail role can submit through the dedicated From identity and SES configuration set. File-inspection failures have a retained queue and CloudWatch alarm; do not manufacture a clean result to unblock a failed scan.

Scanning and storage incur usage charges. These are on-demand services with bounded upload/traffic allowances, not an always-on scanning server. Inspect queue alarms, storage usage, content decisions and sender complaints before raising limits.

The AWS `EnableUploads` parameter is separate from the portal pilot and sending flags. Enabling verified private scanning does not enable customer access or dispatch. General bulk sending remains subject to the existing concurrency, feedback and customer billing-pilot launch gates. Do not use synthetic tests as proof of customer-wallet acceptance or 10,000/day throughput.

Reproducible runtime dependencies are pinned in `services/email_aws/requirements.txt`. Packaging includes the Linux Python 3.13 Pillow wheel and pure-Python pypdf; Windows test binaries must never be packaged for Lambda. Keep exact source/archive receipts and encrypted rollback settings private.
