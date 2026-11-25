import { useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Check, ChevronRight, Code2, Copy, Download, KeyRound, ShieldCheck, Terminal } from 'lucide-react';
import { Footer } from '@/components/layout/Footer';
import { SignedInPublicHeader } from '@/components/layout/PublicChrome';
import { SMS_API_BASE, examplePayload, smsApiExamples } from '@/lib/sms-api-examples';
import './ApiDocumentation.css';
import { ApiExampleCode as CodeBlock, ApiQuoteCheck } from '@/components/settings/ApiIntegrationHelp';
import { apiTerms } from '@/lib/sms-api-setup';
import '@/components/settings/api-integration-help.css';

const sections = [['start','Quick start'],['safe-check','Safe connection check'],['glossary','What does this mean?'],['authentication','Authentication'],['examples','Code examples'],['reference','API reference'],['billing','Credits & billing'],['reliability','Retries & delivery'],['errors','Errors & limits'],['platforms','Apps & platforms'],['security','Go-live checklist']] as const;
const endpoints = [
  ['POST','/sms/quotes','sms:quote','Calculate recipients, encoding and current credit estimate. No funds are reserved. A scheduled quote also needs sms:schedule.'],
  ['POST','/sms/messages','sms:send','Queue SMS to 1–1,000 phone numbers. Requires an Idempotency-Key. Include scheduled_at and sms:schedule permission to schedule.'],
  ['GET','/sms/messages/{id}','sms:read','Read a simple sending result for this connection. Detailed delivery results are available inside the portal.'],
  ['POST','/sms/messages/{id}/cancel','sms:cancel','Cancel queued or scheduled work before processing starts. Repeated cancellation is safe; started campaigns return 409. No JSON body is needed.'],
  ['GET','/balance','wallet:read','Read the authorizing account’s available balance and credits, excluding reservations.'],
  ['GET','/usage','sms:read','Read today’s admitted credits, campaign count and daily allowance for this connection. Uses the portal’s South African business day.'],
] as const;
const fields = [
  ['content','Required string','Non-empty UTF-8 text, up to 12,000 bytes, subject to SMS segment limits. Personalize in your application; {{template_variables}} are not supported.'],
  ['recipients','Required string[]','1–1,000 phone-number strings, each up to 40 bytes. Use international format, e.g. +27820000001. Keep numbers as strings, never spreadsheet/numeric values.'],
  ['name','Optional string','Campaign name for your records, up to 100 bytes. Recipients do not see it. If omitted, a name includes your connection, South African date/time and a short reference.'],
  ['include_opt_out','Optional boolean','Default false. Adds the account’s configured opt-out footer when true, including its segment cost. Opt-out suppression always applies regardless of this setting.'],
  ['external_reference','Optional string','Your job/order reference, up to 120 bytes. Returned for correlation; it does not replace Idempotency-Key. Default: empty.'],
  ['max_credits','Optional integer','1–6,000. Maximum admitted credits you authorize for this request. If recalculation exceeds it, nothing is queued or reserved.'],
  ['scheduled_at','Optional string or null','Future ISO 8601 date/time with seconds and explicit offset or Z. Omit or use null for immediate sending. Requires sms:schedule as well as sms:send.'],
] as const;
function Note({ children }: { children: ReactNode }) { return <div className="api-note">
<ShieldCheck size={19} aria-hidden="true"/>
<div>{children}</div>
</div>; }
export default function ApiDocumentation() {
  useEffect(() => {
    const reveal = () => { const term = window.location.hash.slice(6); if (window.location.hash.startsWith('#term-') && Object.prototype.hasOwnProperty.call(apiTerms, term)) { const element = document.getElementById(`term-${term}`); if (element instanceof HTMLDetailsElement) element.open = true; } };
    reveal(); window.addEventListener('hashchange', reveal); return () => window.removeEventListener('hashchange', reveal);
  }, []);
  const [language, setLanguage] = useState<string>('curl');
  const example = smsApiExamples.find(item => item.id === language) || smsApiExamples[0];
  return <>
<SignedInPublicHeader/>
<main className="api-docs">
    <header className="api-hero">
<div className="api-wrap">
<p className="api-eyebrow">
<Code2 size={17}/> DEVELOPERS / SMS API v1</p>
<h1>Your software.<br/>
<span>Our SMS delivery.</span>
</h1>
<p className="api-lead">Connect your website, business system or IEOSUIA application to one secure messaging service. Quote, send, schedule and follow delivery using the credits already in your account.</p>
<div className="api-hero-actions">
<a className="api-primary" href="#start">Start integrating <ArrowRight size={17}/>
</a>
<a className="api-secondary" href="/sms-api-v1.openapi.json" download>
<Download size={17}/> OpenAPI specification</a>
</div>
<div className="api-hero-meta">
<span>REST + JSON</span>
<span>Server-side keys</span>
<span>Existing prepaid credits</span>
</div>
</div>
</header>
    <section className="api-wrap api-security-required" aria-labelledby="api-extra-security">
<ShieldCheck size={23} aria-hidden="true"/>
<div>
<h2 id="api-extra-security">Extra sign-in verification is optional</h2>
<p>Create or replace API keys with your verified, active account. The extra email-code sign-in check is optional in <Link to="/settings?tab=security">Settings → Security</Link>. If you enable it, complete that check when signing in before issuing a key.</p>
<ul>
<li>Verified, active account</li>
<li>Extra sign-in check only if you choose to enable it</li>
<li>API keys stored only on your server</li>
</ul>
<p className="api-security-detail">This setting applies only to the SMS Portal, not your other IEOSUIA apps. It is optional for both portal and API access. Your backend authenticates API requests with its key; it does not enter a six-digit code for each SMS.</p>
</div>
</section>
    <div className="api-wrap api-layout">
<aside className="api-nav">
<nav aria-label="API documentation sections">{sections.map(([id,label]) => <a key={id} href={`#${id}`}>{label}<ChevronRight size={14}/>
</a>)}</nav>
<div className="api-nav-help">
<KeyRound size={19}/>
<strong>Already have an account?</strong>
<Link to="/settings?tab=api">Manage API access <ArrowRight size={14}/>
</Link>
<p>Keep your key on your server. This page never asks for it.</p>
</div>
</aside>
<div className="api-content">
      <section id="start">
<p className="api-section-label">01 / GET CONNECTED</p>
<h2>From account to first message.</h2>
<p>Use a separate connection for each app and environment. Each connection can only spend the credits of the SMS Portal account that authorizes it.</p>
<ol className="api-steps">
<li>
<strong>Prepare your account</strong>
<p>Sign in with your verified account and make sure you have available credits. If you have enabled the optional extra SMS Portal sign-in check, complete it when signing in.</p>
</li>
<li>
<strong>Create an API connection</strong>
<p>Open <Link to="/settings?tab=api">Settings → API access</Link>. Name the connection, select its product label, permissions and daily allowance. Start with <code>sms:quote</code>, <code>sms:send</code> and <code>sms:read</code>.</p>
</li>
<li>
<strong>Save the key securely</strong>
<p>The complete key is displayed once. Put it in a backend environment variable named <code>IEOSUIA_SMS_API_KEY</code> or your server’s secret manager. Never commit it to Git.</p>
</li>
<li>
<strong>Quote, test, then scale</strong>
<p>Get a quote first, check exclusions and cost, then send to one consenting test recipient. Store the returned request ID and poll status before using your real audience.</p>
</li>
</ol>
<Note>API access must be enabled on the portal before a connection can be created. If Settings reports that setup is pending, contact <a href="mailto:sms@ieosuia.com">sms@ieosuia.com</a>. There is no anonymous sending or public demo key.</Note>
</section>
      <section id="authentication">
<p className="api-section-label">02 / AUTHENTICATE</p>
<h2>A key for your backend. Not your browser.</h2>
<CodeBlock label="Base URL and headers" code={`${SMS_API_BASE}\n\nAuthorization: Bearer <your-server-side-key>\nContent-Type: application/json\nIdempotency-Key: <stable-unique-job-key>`}/>
<p>All requests use HTTPS. The idempotency header is required for sending, not for a quote or GET request. Keys in query strings are rejected. Browser-origin requests are deliberately rejected; do not call from React, a public form, or a distributed mobile/desktop application directly.</p>
<p>Use your own authenticated backend to authorize the person, rate-limit their action and call this API. A key identifies the connection and its account—not the end user. Never let a public contact form turn into an unrestricted SMS relay.</p>
<div className="api-table-wrap">
<table>
<caption>Available key permissions</caption>
<thead>
<tr>
<th>Permission</th>
<th>Allows</th>
</tr>
</thead>
<tbody>{[['sms:quote','Estimate a message without reserving credits.'],['sms:send','Queue an immediate message.'],['sms:schedule','Add future scheduling to quotes and sends.'],['sms:read','Read this connection’s request statuses and today’s usage.'],['sms:cancel','Cancel this connection’s queued/scheduled requests.'],['wallet:read','Read the authorizing account’s available balance.']].map(([scope,detail]) => <tr key={scope}>
<td>
<code>{scope}</code>
</td>
<td>{detail}</td>
</tr>)}</tbody>
</table>
</div>
<p>Keys expire after 90 days. Up to two active keys may overlap: create a replacement, update your server, verify it and revoke the old key. Revoking a connection blocks all its keys. Password or security-setting changes, suspension and expiry can invalidate keys. Revocation does not cancel messages already queued.</p>
</section>
      <section id="examples">
<p className="api-section-label">03 / CHOOSE YOUR LANGUAGE</p>
<h2>A familiar starting point.</h2>
<p>These are illustrative HTTP examples, not installed SDKs. Replace the example phone number and store one stable, unique idempotency key per real job. <strong>Executing a send example can use credits and send a real SMS.</strong> Nothing on this page executes it.</p>
<div className="api-languages" role="tablist" aria-label="Example programming language" onKeyDown={event => { if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return; event.preventDefault(); const current = smsApiExamples.findIndex(item => item.id === language); const index = event.key === 'Home' ? 0 : event.key === 'End' ? smsApiExamples.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + smsApiExamples.length) % smsApiExamples.length; const next = smsApiExamples[index]; setLanguage(next.id); document.getElementById(`language-${next.id}`)?.focus(); }}>{smsApiExamples.map(item => <button key={item.id} id={`language-${item.id}`} type="button" role="tab" tabIndex={item.id===language ? 0 : -1} aria-selected={item.id===language} aria-controls="language-example" onClick={() => setLanguage(item.id)}>{item.label}</button>)}</div>
<div id="language-example" role="tabpanel" aria-labelledby={`language-${language}`}>
<p className="api-runtime">
<Terminal size={16}/>{example.runtime}</p>
<CodeBlock key={language} label={`${example.label} send example`} code={example.code}/>
</div>
<details className="api-details">
<summary>How do I quote before sending?</summary>
<p>Use the same JSON body with <code>POST /sms/quotes</code>. A quote needs <code>sms:quote</code> and does not need an idempotency header. It does not send, reserve credits or guarantee a later price/balance.</p>
<CodeBlock label="Quote request" code={`curl --max-time 30 --silent --show-error --fail-with-body \\
  '${SMS_API_BASE}/sms/quotes' \\
  --header "Authorization: Bearer $IEOSUIA_SMS_API_KEY" \\
  --header 'Content-Type: application/json' \\
  --data-binary '${JSON.stringify(examplePayload, null, 2)}'`}/>
<CodeBlock label="Illustrative quote response" code={JSON.stringify({success:true,quote:{entered:1,sendable:1,invalid:0,duplicates:0,opted_out:0,encoding:'gsm7',credits_required:1,estimated_charge:'0.3500',currency:'ZAR',available_credits:100,sufficient_balance:true,daily_credit_allowance_remaining:1000,binding:false}},null,2)}/>
<p>Example balances and costs above are illustrative, not your account balance or a guaranteed price.</p>
</details>
</section>
      <section id="reference">
<p className="api-section-label">04 / REQUEST & RESPONSE</p>
<h2>The complete v1 surface.</h2>
<p>All paths below are relative to <code>{SMS_API_BASE}</code>. JSON request bodies must be objects and fit within 64 KiB. Unknown fields and query parameters are rejected.</p>
<div className="api-endpoints">{endpoints.map(([method,path,scope,detail]) => <article key={method+path}>
<div>
<span className={`api-method api-method-${method.toLowerCase()}`}>{method}</span>
<code>{path}</code>
</div>
<p>{detail}</p>
<small>Permission: <code>{scope}</code>
</small>
</article>)}</div>
<h3>Quote and send fields</h3>
<div className="api-table-wrap">
<table>
<caption>Accepted JSON fields</caption>
<thead>
<tr>
<th>Field</th>
<th>Type</th>
<th>Rules</th>
</tr>
</thead>
<tbody>{fields.map(([name,type,rule]) => <tr key={name}>
<td>
<code>{name}</code>
</td>
<td>{type}</td>
<td>{rule}</td>
</tr>)}</tbody>
</table>
</div>
<p>Do not supply account IDs, payer IDs, prices, sender IDs, contact/group IDs, webhook URLs or attachments. Sender configuration is controlled by the portal. Duplicate numbers are sent to once; invalid and account-opted-out numbers are excluded. Check the quote’s counts before submitting.</p>
<h3>Scheduled sends</h3>
<CodeBlock label="Scheduling field example" code={'"scheduled_at": "2027-01-15T11:00:00+02:00"'}/>
<p>Choose a future date at the time you send. South Africa uses <code>+02:00</code>. Scheduling reserves credits now; the queue becomes eligible at the scheduled instant. Network delivery can happen later. Omitted or null means send now; timezone-free dates are rejected.</p>
<h3>Queued is not delivered</h3>
<CodeBlock label="Illustrative 202 send response" code={JSON.stringify({success:true,replayed:false,message:{id:'0123456789abcdef0123456789abcdef',reference:'API-EXAMPLE-NOT-A-CREDENTIAL',external_reference:'appointments:example-0001',status:'queued',state:'queued',complete:false,scheduled_at:null,created_at:1793872800,recipients:1,credits_required:1,acceptance_is_not_delivery:true}},null,2)}/>
<p>Store <code>message.id</code> on the original business job and use it when checking status. <code>reference</code> helps you find the same request inside the portal. <code>replayed: true</code> means the original request was returned, not sent again.</p>
<h3 id="sending-results">Simple sending results</h3>
<div className="api-table-wrap"><table><caption>Public sending statuses</caption><thead><tr><th>Status</th><th>Meaning</th></tr></thead><tbody>
<tr><td><code>queued</code></td><td>Preparing, scheduled, sending or awaiting confirmation. Keep the request pending; do not start a replacement send.</td></tr>
<tr><td><code>sent</code></td><td>All recipients were accepted for sending. This is not confirmation of delivery to their phones.</td></tr>
<tr><td><code>not_sent</code></td><td>No recipients were sent to, and the result is final. This includes a successful cancellation before sending.</td></tr>
<tr><td><code>partially_sent</code></td><td>Sending has finished with mixed results. Open the portal to review recipients before deciding what to do next; do not resend the whole batch.</td></tr>
</tbody></table></div>
<p>Build your integration around <code>message.status</code> and <code>message.complete</code>. While <code>complete</code> is false, keep checking with backoff. A scheduled request stays queued and includes <code>scheduled_at</code>. A later delivery failure does not change a confirmed sending result to not_sent.</p>
<p>The <code>state</code> field is a deprecated alias of <code>status</code>, not a separate lifecycle. The result contract now uses these four statuses; previous detailed result counters are no longer returned. Update an existing integration to read status and complete before using this release.</p>
<h3>Your campaigns in the portal</h3>
<p>Each API send creates one campaign in <Link to="/sms-campaigns">SMS Campaigns</Link>. Repeating the same request key returns that campaign, not a new one. Supply your own name or let us label it with the connection name, date/time and a short reference. The campaign shows its API connection, API reference and your external_reference. Sign in to view recipient-level delivery details. Your API key does not grant another connection’s records or portal access.</p>
<h3>Read status or cancel</h3>
<CodeBlock label="Read status" code={`curl --max-time 30 --silent --show-error --fail-with-body \\
  '${SMS_API_BASE}/sms/messages/0123456789abcdef0123456789abcdef' \\
  --header "Authorization: Bearer $IEOSUIA_SMS_API_KEY"`}/>
<p>Status returns <code>{'{"success":true,"message":{...}}'}</code> with the same fields as the send receipt. Cancel with POST to that ID’s <code>/cancel</code> path. You cannot inspect or cancel another connection’s request, even if both connections belong to the same account.</p>
</section>
      <section id="billing">
<p className="api-section-label">05 / KEEP SPENDING PREDICTABLE</p>
<h2>One account. One prepaid wallet.</h2>
<p>API messages use the authorizing account’s existing credits and pricing. One credit pays for one billable SMS segment to one recipient, not necessarily one complete message. Unicode, long messages, personalization and the opt-out footer affect segments. Quote the final text rather than estimating from recipient count alone.</p>
<p>You need enough available credits and connection allowance before sending. Required credits are reserved when the request is accepted. A quote is non-binding; <code>max_credits</code> can limit what you authorize at send time.</p>
<p>The default app allowance is 1,000 admitted credits per South African day, with an owner-selected allowance up to 50,000. Admitted means accepted into the local queue: failed or cancelled campaigns still count toward this abuse-prevention allowance until midnight. This is separate from charged credits. A cancellation releases eligible reserved funds, not the admission allowance.</p>
<CodeBlock label="Illustrative balance and usage responses" code={JSON.stringify({success:true,balance:{available_credits:100,available_amount:'35.0000',currency:'ZAR'}},null,2)+'\n\n'+JSON.stringify({success:true,usage:{date:'2026-11-05',timezone:'Africa/Johannesburg',admitted_credits:1,campaigns:1,daily_credit_limit:1000,cancelled_and_failed_requests_count_toward_allowance:true}},null,2)}/>
<p>Balance uses <code>GET /balance</code>; usage uses <code>GET /usage</code>. Values above are illustrative. Other users or apps on the account may reserve credits between a balance check and sending. See <Link to="/pricing">current credit purchase pricing</Link>.</p>
<Note>Alternative organization wallets are not selectable in v1. For Tenant Management, use the property owner’s authorized account/connection when the owner is responsible for the cost. A tenant-supplied owner ID is never payment authorization.</Note>
</section>
      <section id="reliability">
<p className="api-section-label">06 / HANDLE REAL-WORLD NETWORKS</p>
<h2>Retry safely. Follow the actual result.</h2>
<ol className="api-steps">
<li>
<strong>Save the job before calling</strong>
<p>Generate a 16–100 character idempotency key starting with a letter or digit; remaining characters may include letters, digits, dots, underscores, colons and hyphens. Persist it with the exact message details. A different intentional send needs its own key.</p>
</li>
<li>
<strong>Keep retries identical</strong>
<p>After a timeout or temporary 503, retry with exponential backoff using the same key and payload. Reordered recipients are accepted for replay; changed content, recipients or other details return 409. Do not generate a new key because a result is still pending.</p>
</li>
<li>
<strong>Track sending without exposing delivery details</strong>
<p>HTTP 202 confirms that the request was accepted, not that recipients received it. Read <a href="#sending-results">the simple sending statuses</a>; view the detailed delivery breakdown after signing in to the portal. Keep pending results pending, and never retry a whole partially sent batch automatically.</p>
</li>
<li>
<strong>Poll without flooding</strong>
<p>As a client-side starting point, poll after 10 seconds, then back off to 30–60 seconds while pending. Stop polling finalized work routinely. Missing delivery confirmation is not proof of failure or permission to resend.</p>
</li>
</ol>
<p>Idempotency survives credential rotation within the same connection. It does not carry over to a new connection. Keep old request IDs and keys with your job records. Connection labels and <code>external_reference</code> help your bookkeeping; neither grants another payer’s authorization.</p>
<p>Outbound webhooks to your application are <strong>not yet available</strong>. Poll the status endpoint instead. Recipient-level API exports, contact APIs, OAuth client credentials and historical date-range API reports are not included in v1.</p>
</section>
      <section id="errors">
<p className="api-section-label">07 / TROUBLESHOOT</p>
<h2>Clear errors. No silent resends.</h2>
<CodeBlock label="Illustrative error response" code={JSON.stringify({success:false,message:'This API key does not have the required permission.',error:{code:'INSUFFICIENT_SCOPE',message:'This API key does not have the required permission.'},code:'INSUFFICIENT_SCOPE'},null,2)}/>
<div className="api-table-wrap">
<table>
<caption>HTTP status guide</caption>
<thead>
<tr>
<th>Status</th>
<th>Meaning and action</th>
</tr>
</thead>
<tbody>{[['400','HTTPS_REQUIRED: call the HTTPS endpoint.'],['401','Invalid, expired, revoked or security-invalidated credential. Check or rotate it in Settings; never print it in logs.'],['403','Insufficient permission or a browser Origin header. Check scopes and move calls to your backend.'],['404','Unknown request, or a request owned by another account/connection.'],['409','Idempotency conflict, insufficient credits/account allowance, max_credits exceeded, or cancellation too late. Inspect the error code; do not change a key to bypass it.'],['413 / 415','Body over 64 KiB, or Content-Type is not application/json.'],['422','Invalid fields, body, phone input, schedule or missing/invalid idempotency key. Correct before sending.'],['429','Connection/IP request limit or daily app allowance reached. Honor Retry-After for request limits. A daily allowance still requires waiting until the next business day or reviewing your allowance.'],['503','API disabled/not ready or temporary service error. If a send may have reached the server, retry the same job key, not a new one.']].map(([status,detail]) => <tr key={status}>
<td>
<code>{status}</code>
</td>
<td>{detail}</td>
</tr>)}</tbody>
</table>
</div>
<p>Connections default to 60 authenticated API requests per minute shared by their keys. Quotes, status and balance checks count too. Honor Retry-After when a request is rate limited, including when several applications share an IP address. Request limits are not SMS delivery-time guarantees.</p>
<p>If support is needed, share the API request ID, error code and time—not your API key, password, full message or complete contact list. Use <a href="mailto:sms@ieosuia.com">sms@ieosuia.com</a>; report exposed keys or suspicious access to <a href="mailto:security@ieosuia.com">security@ieosuia.com</a>.</p>
</section>
      <section id="platforms">
<p className="api-section-label">08 / FIT YOUR STACK</p>
<h2>One HTTP contract, many integrations.</h2>
<div className="api-platform-grid">
<article>
<h3>Websites & WordPress</h3>
<p>Call from a server-side route or plugin using the PHP example. Check your own user’s session and permission. Add CSRF protection, consent checks and rate limits before triggering SMS. Never embed the key in page source.</p>
</article>
<article>
<h3>React, mobile & desktop</h3>
<p>Your app calls your authenticated backend; the backend calls IEOSUIA. Device binaries and browser bundles are not safe secret stores. Do not forward the IEOSUIA key to the device.</p>
</article>
<article>
<h3>Laravel, Django & business systems</h3>
<p>Put calls in a background job with a durable job key. Store the API request ID, quote, source reference and eventual outcome against your own record. Preserve keys and payloads across job retries.</p>
</article>
<article>
<h3>Server-side workflow tools</h3>
<p>Use an HTTP request action that keeps credentials secret. Add Bearer authentication and JSON headers; persist the idempotency key in the workflow’s business record. Disable blind automatic retries that generate new keys.</p>
</article>
<article>
<h3>Events & Security OS</h3>
<p>Create a connection for the account authorizing reminders or alerts. Product labels help identify use; they are not verified product identities. Your app is still responsible for authorizing the initiating person.</p>
</article>
<article>
<h3>Shared hosting</h3>
<p>Your backend needs outbound HTTPS access to sms.ieosuia.com. If the host blocks it, ask the host to allow that connection. A bot-protection HTML page is not a JSON API success; investigate rather than repeatedly submitting.</p>
</article>
</div>
</section>
      <section id="security">
<p className="api-section-label">09 / READY FOR PRODUCTION</p>
<h2>A small checklist before you go live.</h2>
<ul className="api-checklist">{['Use separate test/production connections and the least permissions needed.','Store keys only server-side; redact Authorization, message text and recipient details in logs.','Authenticate and authorize your own users; enforce consent, opt-outs and sensible per-user spending limits.','Quote finalized text, check excluded recipients and provide max_credits on sends.','Persist a stable job key and test a timeout/replay without an extra reservation.','Confirm scheduling timezones and separate provider acceptance from delivery.','Test one consenting recipient before expanding the audience. Monitor available credits and app allowances.','Rotate keys before expiry; revoke exposed keys immediately and investigate queued requests.'].map(text => <li key={text}>
<Check size={17}/>
<span>{text}</span>
</li>)}</ul>
<div className="api-bottom">
<div>
<h3>Build against the contract.</h3>
<p>Download the machine-readable OpenAPI definition or manage your connection in the portal.</p>
</div>
<a className="api-primary" href="/sms-api-v1.openapi.json" download>
<Download size={16}/> Download OpenAPI</a>
<Link className="api-secondary" to="/settings?tab=api">Manage API access <ArrowRight size={16}/>
</Link>
</div>
<p className="api-last-updated">API v1 · Guide updated 5 November 2026 · SMS only. Email sending is not part of this API.</p>
</section>
      <section id="safe-check">
<p className="api-section-label">10 / YOUR FIRST CONNECTION CHECK</p>
<h2>Check safely before sending anything.</h2>
<p>First <Link to="/settings?tab=api">open the guided API setup</Link> to secure your account, create a connection and store the key on your server. Then run this quote-only check. When your server has called the API, refresh connection activity in Settings. A recorded key request proves that the key was recognised—not that a quote succeeded or an SMS was delivered.</p>
<ApiQuoteCheck/>
<p>A live SMS test is separate and optional: get the recipient’s agreement, review your quote, and follow the <a href="#examples">real-send examples</a>. This can use credits. There is no browser-side Send button here.</p>
</section>
      <section id="glossary">
<p className="api-section-label">11 / PLAIN-LANGUAGE HELP</p>
<h2>What does this mean?</h2>
<p>Open any term for a short explanation. The same help is available beside the connection settings.</p>
<div className="api-glossary">{Object.entries(apiTerms).map(([term,[label,explanation]]) => <details key={term} id={`term-${term}`}>
<summary>{label}</summary>
<p>{explanation}</p>{term === 'quote' && <a href="#safe-check">Run the safe quote check</a>}{term === 'key' && <Link to="/settings?tab=api">Open API access settings</Link>}{term === 'idempotency' && <a href="#reliability">See retry instructions</a>}</details>)}</div>
</section>
    </div>
</div>
  </main>
<Footer/>
</>;
}
