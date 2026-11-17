import { smsApiExamples } from './sms-api-examples';

export const apiTerms = {
  connection: ['Connection', 'A named link between one backend application and your SMS Portal account. Give each website and test/production environment its own connection so you can limit or revoke it independently.'],
  key: ['API key', 'A secret password for your backend application. It can spend this account’s credits within its permissions. Store it in a server environment variable or secret manager, never in browser code or a public repository.'],
  product: ['Product label', 'A label to help you recognise the application. It does not verify ownership of that product or authorise another account to pay. Choose Other / custom for software not listed.'],
  allowance: ['Daily credit allowance', 'The maximum SMS credits this connection can admit to the queue per South African day. This is a spending safeguard, not a credit purchase. Cancelled and failed requests still count toward this allowance until midnight; actual charges follow normal SMS billing.'],
  permissions: ['Permissions', 'Choose only what the application needs. Calculate credits allows safe quotes; Send messages allows real SMS; Read status follows results. Scheduling and cancellation need their own permissions.'],
  backend: ['Backend / server-side', 'The private server running your website or software. Browser JavaScript and installed mobile/desktop apps must call your authenticated backend; they must not hold the IEOSUIA API key.'],
  quote: ['Quote', 'A calculation of eligible recipients, message parts and estimated credits. It sends no SMS and does not reserve or deduct credits. A successful quote is not a delivery confirmation or a guarantee of funds when you send later. It still counts as an API request.'],
  idempotency: ['Idempotency key', 'Your unique reference for one intended send. Save it before sending and reuse the same key and message details when retrying after a timeout. This prevents a retry from creating a second campaign. A different intentional message needs a new key.'],
  segment: ['SMS segment / credit', 'One billable SMS part to one recipient. Long messages, Unicode and an opt-out footer can use several parts, so one complete message may cost more than one credit.'],
  status: ['Sending results', 'Queued means sending is pending. Sent confirms acceptance for sending, not delivery to a phone. Not sent is a final unsuccessful result. Partially sent means a finished batch has mixed outcomes. Check detailed delivery results inside the portal; never resend a pending or partially sent batch automatically.'],
} as const;
export type ApiTerm = keyof typeof apiTerms;

/** Quote-only variants reuse the reviewed payload/runtime examples, never an API key. */
export const smsApiQuoteExamples = smsApiExamples.map(example => {
  let code = example.code.split('/sms/messages').join('/sms/quotes')
    .split('\n').filter(line => !/Idempotency-Key|jobKey|Save a unique key per job|Persist once per job/.test(line)).join('\n');
  if (example.id === 'curl') code = code.replace(/# Inspect the HTTP\/JSON result[\s\S]*$/, '# HTTP 200 + success:true + quote.binding:false means the quote succeeded.\n# This does not send, reserve or deduct credits.');
  if (example.id === 'javascript') code = code.replace('console.log(result.message.id, result.message.status);', 'console.log(result.quote);').replace(/\/\/ Save the ID[\s\S]*$/, '// Check success:true and quote.binding:false. No SMS was sent.');
  if (example.id === 'python') code = code.replace("print(result['message']['id'], result['message']['status'])", "print(result['quote'])").replace(/# Persist the job key[\s\S]*$/, '# Check success:true and quote.binding:false. No SMS was sent.');
  if (example.id === 'php') code = code.replace('Request uncertain; retry SAME key', 'Quote request failed; no send was requested').replace('$status !== 202', '$status !== 200').replace("echo $result['message']['id']; // Store against the original job.", "echo json_encode($result['quote'], JSON_THROW_ON_ERROR); // No SMS was sent.");
  if (example.id === 'csharp') code = code.replace('Console.WriteLine(json.RootElement.GetProperty("message").GetProperty("id"));', 'Console.WriteLine(json.RootElement.GetProperty("quote"));').replace(/\/\/ Persist the ID[\s\S]*$/, '// Check success:true and quote.binding:false. No SMS was sent.');
  if (example.id === 'java') code = code.replace('class SendSms', 'class QuoteSms').replace('response.statusCode() != 202', 'response.statusCode() != 200').replace('SMS request failed', 'Quote request failed').replace('// Parse response.body() with your JSON library; persist message.id.', 'System.out.println(response.body()); // Check success:true and quote.binding:false.');
  return { ...example, code };
});

export type QuoteResponseCheck = { ok: true; sendable: number; credits: number; balanceSufficient: boolean } | { ok: false; message: string };
/** Local interpretation only. Pasted JSON is never independent authentication proof. */
export function checkQuoteResponse(raw: string): QuoteResponseCheck {
  if (raw.length > 16384) return { ok: false, message: 'Paste only the small JSON quote response, not a log or contact list.' };
  if (/smsk_[a-f0-9]{32}\.[a-f0-9]{64}/i.test(raw) || /authorization\s*:/i.test(raw)) return { ok: false, message: 'Do not paste API keys or request headers. Clear this field and paste only the quote response.' };
  let value: unknown;
  try { value = JSON.parse(raw); } catch { return { ok: false, message: 'This is not a JSON response. If your server received an HTML or bot-protection page, contact your host rather than trying a send.' }; }
  if (!value || typeof value !== 'object' || Array.isArray(value)) return { ok: false, message: 'Paste the complete JSON response object from POST /sms/quotes.' };
  const response = value as Record<string, unknown>;
  if (response.success !== true) return { ok: false, message: 'The request did not report success. Check its error code in the troubleshooting guide. Do not paste your API key.' };
  if (!response.quote || typeof response.quote !== 'object' || Array.isArray(response.quote)) return { ok: false, message: 'This is not a quote response. Use POST /sms/quotes, not the sending endpoint.' };
  const q = response.quote as Record<string, unknown>;
  if (q.binding !== false || !Number.isSafeInteger(q.sendable) || Number(q.sendable) < 0 || !Number.isSafeInteger(q.credits_required) || Number(q.credits_required) < 0 || typeof q.sufficient_balance !== 'boolean') return { ok: false, message: 'The quote response is missing expected fields. Check the full response against the API guide.' };
  return { ok: true, sendable: Number(q.sendable), credits: Number(q.credits_required), balanceSufficient: q.sufficient_balance };
}
