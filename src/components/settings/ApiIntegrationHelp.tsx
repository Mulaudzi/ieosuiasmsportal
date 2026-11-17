import { useState } from 'react';
import { Check, Copy, Download, ShieldCheck } from 'lucide-react';
import { apiTerms, checkQuoteResponse, smsApiQuoteExamples, type ApiTerm } from '@/lib/sms-api-setup';

export function ApiTermHelp({ term }: { term: ApiTerm }) {
  const [label, explanation] = apiTerms[term];
  return <details className="integration-help"><summary>What is {label.toLowerCase()}?</summary><p>{explanation}</p><a href={`/developers#term-${term}`} target="_blank" rel="noopener noreferrer">Read in the API guide ↗</a></details>;
}

export function ApiExampleCode({ code, label = 'Example' }: { code: string; label?: string }) {
  const [copied, setCopied] = useState(false), [error, setError] = useState(false);
  return <div className="api-code"><div className="api-code-top"><span>{label}</span><button type="button" onClick={async () => { try { await navigator.clipboard.writeText(code); setCopied(true); setError(false); } catch { setError(true); } }} aria-label={`Copy ${label}`}>{copied ? <Check size={15}/> : <Copy size={15}/>} {copied ? 'Copied' : 'Copy'}</button></div><pre tabIndex={0}><code>{code}</code></pre>{error && <p role="status">Copy is unavailable. Select and copy the example above.</p>}</div>;
}

export function ApiQuoteCheck({ instance = 'guide' }: { instance?: string }) {
  const [language, setLanguage] = useState('curl'), [response, setResponse] = useState(''), [result, setResult] = useState<ReturnType<typeof checkQuoteResponse> | null>(null);
  const example = smsApiQuoteExamples.find(item => item.id === language)!;
  const extension = { curl: 'sh', javascript: 'mjs', python: 'py', php: 'php', csharp: 'cs', java: 'java' }[language];
  const clear = () => { setResponse(''); setResult(null); };
  const explain = () => { const checked = checkQuoteResponse(response); if (checked.ok === false && checked.message.includes('Do not paste')) setResponse(''); setResult(checked); };
  return <div className="api-quote-check">
    <div className="integration-safe-note"><ShieldCheck size={19} aria-hidden="true"/><p><strong>Safe first test: quote only.</strong> Run this on your own server. It sends no SMS and does not reserve or deduct credits. This page does not execute API requests or ask for your API key.</p></div>
    <label htmlFor={`${instance}-quote-language`}>Your server language</label><select id={`${instance}-quote-language`} value={language} onChange={event => { setLanguage(event.target.value); }}><option value="curl">cURL / command line</option>{smsApiQuoteExamples.filter(item => item.id !== 'curl').map(item => <option key={item.id} value={item.id}>{item.label}</option>)}</select>
    <p>{example.runtime}</p><ApiTermHelp term="backend"/>
    <ol><li>Store your key in the server environment variable <code>IEOSUIA_SMS_API_KEY</code>. Do not paste it here.</li><li>Copy or download the quote example. Keep the <code>/sms/quotes</code> endpoint. Replace the sample number with your own test number.</li><li>Run it on your server and inspect the HTTP/JSON response. Expect HTTP 200, <code>success: true</code> and <code>quote.binding: false</code>.</li></ol>
    <ApiExampleCode key={language} label={`${example.label} quote-only check`} code={example.code}/>
    <a className="integration-download" href={`data:text/plain;charset=utf-8,${encodeURIComponent(example.code)}`} download={language === 'java' ? 'QuoteSms.java' : `ieosuia-quote-check.${extension}`}><Download size={16} aria-hidden="true"/>Download {example.label} quote example</a>
    <details className="integration-response"><summary>Help me understand my quote response</summary><p>Optionally paste only the JSON response. It is checked in this browser, never uploaded or saved. This explains the response; it does not independently verify your backend or mark a real SMS delivered.</p><label htmlFor={`${instance}-quote-response`}>Quote response JSON — never your key</label><textarea id={`${instance}-quote-response`} autoComplete="off" spellCheck={false} maxLength={16385} rows={5} value={response} onChange={event => { setResponse(event.target.value); setResult(null); }}/><div className="integration-response-actions"><button type="button" disabled={!response.trim()} onClick={explain}>Explain quote response</button><button type="button" onClick={clear}>Clear response</button></div>
    {result && <div role="status" className="integration-result">{result.ok === true ? <><strong>Successful, non-binding quote response.</strong><p>{result.sendable} sendable recipient(s); {result.credits} estimated SMS credit(s). {result.balanceSufficient ? 'The reported balance was sufficient at quote time.' : 'The reported balance was insufficient; add credits before sending.'} {result.sendable === 0 ? 'No recipients are sendable. Review invalid and opted-out exclusions before continuing.' : 'No SMS was sent by this quote.'}</p><p>This is a local interpretation of the JSON you supplied, not independent connection or delivery verification.</p></> : result.message}</div>}</details>
    <p><a href="/developers#errors" target="_blank" rel="noopener noreferrer">Troubleshoot an error ↗</a> · <a href="/developers#examples" target="_blank" rel="noopener noreferrer">See real-send examples (use credits) ↗</a></p>
  </div>;
}
