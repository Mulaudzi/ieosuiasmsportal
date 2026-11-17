# Business identity and verified reply inboxes

Business identity means the business/organization name and postal address shown in campaign email, together with the customer's authority to represent that business. The existing administrator review records evidence of that authority. It is separate from proving access to an email inbox, and is not an automated company-registry or Meta business-verification check.

In the customer Email Campaigns workspace, the verified portal account email is available as Reply-To automatically. A customer may add up to five different reply inboxes:

1. Enter the additional inbox and request a verification code.
2. Open that inbox and enter its six-digit code in the portal.
3. Select the now-verified address and submit the updated sender details for administrator review.
4. Campaigns created under the approved sender snapshot use that exact Reply-To. The authenticated platform From address remains unchanged.

The code expires after 10 minutes and is single use. Five wrong six-digit attempts lock it. A new code invalidates the previous challenge. Resends have a 60-second cooldown. Service-side limits are three requests per account per UTC hour and ten per day; three per target inbox per hour and five per day; and 100 across the service per day. Additional portal limits are three requests per account per 15 minutes and ten per IP per hour. Requests count even when SMTP handoff fails or the response is lost. Verification does not debit the customer wallet.

Code generation and HMAC-protected challenge state reside in the dedicated AWS state table. The secret code is passed only to the signed portal mail bridge, never a browser status response or URL. Only its keyed hash is stored. The bridge sends one fixed-purpose transactional email using the existing SMTP service with SMTP debugging disabled, acknowledges handoff, and strips the secret from the browser response. No campaign content or customer-selected message is relayed through this endpoint. This does not enable Axxess mass-email processing or broaden the AWS API role's SES permission.

Successful verification creates an owned, versioned proof tied to that exact inbox. Selecting an unverified address, using another customer's challenge or reusing a consumed/expired code fails closed. Removing a verified address invalidates a sender using it. Re-verifying the same address produces a new proof; it does not resurrect a campaign approved under the revoked proof. Worker checks also stop future claims after revocation. Messages already submitted cannot be recalled.

New reservations retain separate account-email and reply-email digests. Migration `030_email_reply_to_account_binding.php` adds one nullable account digest; existing reservations fall back to their original binding. A changed login email still stops future permits, while accepted work retains its reconciliation path. Campaign fingerprints bind the approved sender and recipient snapshot. The portal remains the wallet authority.

The alternate-inbox feature follows the existing email pilot access gate. Deploying it does not enable public campaign sending, provider-isolation gates or the worker schedule. No additional production verification email was sent during acceptance; mail delivery was checked with an injected no-network SMTP capture, and live API checks simulated the authenticated bridge handoff with synthetic accounts.
