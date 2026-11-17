// Documentation only: these examples are never executed by the website.
export const SMS_API_BASE = 'https://sms.ieosuia.com/api/v1';
export const examplePayload = {
  name: 'Appointment reminder',
  content: 'Your appointment is tomorrow at 10:00.',
  recipients: ['+27820000001'],
  include_opt_out: true,
  external_reference: 'appointments:example-0001',
  max_credits: 2,
};
const body = JSON.stringify(examplePayload, null, 2);
export const smsApiExamples = [
  { id: 'curl', label: 'cURL', runtime: 'A server-side shell with cURL. Set IEOSUIA_SMS_API_KEY securely in the server environment.', code: `# Example only. Replace the number with a consenting test recipient.
# Save a unique key per job BEFORE sending; reuse it for retries.
curl --max-time 30 --silent --show-error --fail-with-body \\
  '${SMS_API_BASE}/sms/messages' \\
  --header "Authorization: Bearer $IEOSUIA_SMS_API_KEY" \\
  --header 'Content-Type: application/json' \\
  --header 'Idempotency-Key: appointment-example-0001' \\
  --data-binary '${body}'
# Inspect the HTTP/JSON result. 202 means queued, not delivered.
# Do not add --retry-all-errors or change the key after a timeout.` },
  { id: 'javascript', label: 'Node.js', runtime: 'Node.js 20+ on your backend, not browser JavaScript. Uses built-in fetch; no SDK required.', code: `const key = process.env.IEOSUIA_SMS_API_KEY;
if (!key) throw new Error('Missing server-side API key');
const jobKey = 'appointment-example-0001'; // Persist once per job.
const response = await fetch('${SMS_API_BASE}/sms/messages', {
  method: 'POST',
  headers: {
    Authorization: \`Bearer \${key}\`,
    'Content-Type': 'application/json',
    'Idempotency-Key': jobKey,
  },
  body: JSON.stringify(${body}),
  signal: AbortSignal.timeout(30000),
});
const result = await response.json();
if (!response.ok) throw new Error(result.error?.message || 'Request failed');
console.log(result.message.id, result.message.status);
// Save the ID. On timeout/503, retry this payload with the SAME jobKey.` },
  { id: 'python', label: 'Python', runtime: 'Python 3 on your server. Uses the standard library only; no package installation required.', code: `import json, os
from urllib.request import Request, urlopen
from urllib.error import HTTPError

payload = json.loads('''${body}''')
request = Request('${SMS_API_BASE}/sms/messages',
    data=json.dumps(payload).encode('utf-8'),
    headers={
        'Authorization': 'Bearer ' + os.environ['IEOSUIA_SMS_API_KEY'],
        'Content-Type': 'application/json',
        'Idempotency-Key': 'appointment-example-0001',
    }, method='POST')
try:
    with urlopen(request, timeout=30) as response:
        result = json.load(response)
        print(result['message']['id'], result['message']['status'])
except HTTPError as error:
    # Log only a redacted error code, never headers or full recipients.
    result = json.load(error)
    print(error.code, result.get('error', {}).get('code'))
# Persist the job key; a network timeout is NOT permission to send anew.` },
  { id: 'php', label: 'PHP', runtime: 'PHP 8+ with cURL. Suitable for a PHP backend, Laravel service or server-side WordPress plugin.', code: `<?php
$key = getenv('IEOSUIA_SMS_API_KEY');
if (!$key) throw new RuntimeException('Missing server-side API key');
$body = <<<'JSON'
${body}
JSON;
$request = curl_init('${SMS_API_BASE}/sms/messages');
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$key,
        'Content-Type: application/json',
        'Idempotency-Key: appointment-example-0001',
    ],
    CURLOPT_POSTFIELDS => $body,
]);
$raw = curl_exec($request);
$status = curl_getinfo($request, CURLINFO_HTTP_CODE);
if ($raw === false) throw new RuntimeException('Request uncertain; retry SAME key');
curl_close($request);
$result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if ($status !== 202) throw new RuntimeException($result['error']['code'] ?? 'Request failed');
echo $result['message']['id']; // Store against the original job.
// Never put this code or its key in a public HTML/JavaScript template.` },
  { id: 'csharp', label: 'C# / .NET', runtime: '.NET 8+ server application. Uses HttpClient and System.Text.Json; never embed keys in a desktop/mobile binary.', code: `using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

var key = Environment.GetEnvironmentVariable("IEOSUIA_SMS_API_KEY")
    ?? throw new InvalidOperationException("Missing server-side API key");
using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
using var request = new HttpRequestMessage(HttpMethod.Post,
    "${SMS_API_BASE}/sms/messages");
request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", key);
request.Headers.Add("Idempotency-Key", "appointment-example-0001");
request.Content = new StringContent("""
${body}
""", Encoding.UTF8, "application/json");
using var response = await client.SendAsync(request);
var result = await response.Content.ReadAsStringAsync();
if (!response.IsSuccessStatusCode) throw new HttpRequestException(
    $"SMS request returned {(int)response.StatusCode}; inspect redacted error");
using var json = JsonDocument.Parse(result);
Console.WriteLine(json.RootElement.GetProperty("message").GetProperty("id"));
// Persist the ID and original job key. A timeout may mean queued already.` },
  { id: 'java', label: 'Java', runtime: 'Java 17+ backend. Uses the built-in HTTP client and text blocks; no SMS SDK required.', code: `import java.net.URI;
import java.net.http.*;
import java.time.Duration;

class SendSms {
  public static void main(String[] args) throws Exception {
    String key = System.getenv("IEOSUIA_SMS_API_KEY");
    if (key == null || key.isBlank()) throw new IllegalStateException("Missing API key");
    String body = """
${body}
    """;
    var client = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(10)).build();
    var request = HttpRequest.newBuilder(URI.create("${SMS_API_BASE}/sms/messages"))
      .timeout(Duration.ofSeconds(30))
      .header("Authorization", "Bearer " + key)
      .header("Content-Type", "application/json")
      .header("Idempotency-Key", "appointment-example-0001")
      .POST(HttpRequest.BodyPublishers.ofString(body)).build();
    var response = client.send(request, HttpResponse.BodyHandlers.ofString());
    if (response.statusCode() != 202) throw new IllegalStateException("SMS request failed");
    // Parse response.body() with your JSON library; persist message.id.
    // Never log the key, full payload or recipients.
  }
}` },
] as const;
