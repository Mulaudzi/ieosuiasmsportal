import { useState } from 'react';
import { Check, ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import { ApiQuoteCheck, ApiTermHelp } from './ApiIntegrationHelp';
import type { IntegrationStatus } from '@/lib/sms-integrations';

export function SmsApiSetup({ status, loading, onCreate, onRefresh }: { status?: IntegrationStatus; loading: boolean; onCreate: () => void; onRefresh: () => void }) {
  const [selected, setSelected] = useState(''), [storedFor, setStoredFor] = useState<string[]>([]);
  const connections = status?.connections.filter(app => app.status === 'active') ?? [];
  const connection = connections.find(app => app.id === selected) ?? connections[0];
  const currentKeys = connection?.keys.filter(key => !key.revoked_at && Number(key.expires_at) > Date.now() / 1000) ?? [];
  const lastUsed = currentKeys.reduce((latest, key) => Math.max(latest, Number(key.last_used_at) || 0), 0);
  const secure = status?.can_issue_keys === true, hasKey = currentKeys.length > 0;
  const stored = !!connection && storedFor.includes(connection.id);
  const completed = Number(secure) + Number(hasKey) + Number(stored) + Number(lastUsed > 0);
  return <div className="integration-setup" aria-labelledby="integration-setup-heading">
    <h4 id="integration-setup-heading">Connect your application, step by step</h4>
    <p>{loading ? 'Checking your account and connection setup…' : `${completed} of 4 setup checks complete.`} Progress helps you get started; it never changes your API permissions.</p>
    {connections.length > 0 && <><label htmlFor="setup-connection">Connection to set up</label><select id="setup-connection" className="integration-connection-select" value={connection?.id ?? ''} onChange={event => setSelected(event.target.value)}>{connections.map(app => <option key={app.id} value={app.id}>{app.name} — {app.product}</option>)}</select></>}
    <ol className="integration-setup-steps">
      <li data-complete={secure}><span className="integration-step-number" aria-hidden="true">{secure ? <Check size={16}/> : 1}</span><div><strong>Secure your account {secure ? '— complete' : ''}</strong><p>Verify your email, enable the SMS Portal email-code check, then sign out and back in.</p><Link to="/settings?tab=security">Open Security <ArrowRight size={12} className="inline"/></Link></div></li>
      <li data-complete={hasKey}><span className="integration-step-number" aria-hidden="true">{hasKey ? <Check size={16}/> : 2}</span><div><strong>Create a connection {hasKey ? '— complete' : ''}</strong><p>Use a separate name and key for each application and environment. Include Calculate credits for the safe quote check.</p><button type="button" disabled={!secure || loading} className="integration-text-button" onClick={onCreate}>Add API connection</button><ApiTermHelp term="connection"/></div></li>
      <li data-complete={stored}><span className="integration-step-number" aria-hidden="true">{stored ? <Check size={16}/> : 3}</span><div><strong>Store the key on your server</strong><p>The key is shown once. Save it as <code>IEOSUIA_SMS_API_KEY</code> on your backend. If you lost it, create a replacement rather than sharing a password.</p><label className="integration-confirm"><input type="checkbox" disabled={!hasKey} checked={stored} onChange={event => { if (connection) setStoredFor(previous => event.target.checked ? [...previous, connection.id] : previous.filter(id => id !== connection.id)); }}/>I have stored this connection’s key on my backend</label>{stored && <small>Confirmed by you for this visit; not independently verified.</small>}<ApiTermHelp term="key"/></div></li>
      <li data-complete={lastUsed > 0}><span className="integration-step-number" aria-hidden="true">{lastUsed > 0 ? <Check size={16}/> : 4}</span><div><strong>Check your server connection</strong><p>Run the quote-only example below from your server, then refresh key activity here.</p>{lastUsed > 0 ? <p>Portal observed an authenticated key request at {new Date(lastUsed * 1000).toLocaleString()}. Check your backend’s quote response too: activity alone does not prove the quote succeeded or an SMS was delivered.</p> : <p>No authenticated use is recorded for a current, unrevoked key yet. Older activity is not backfilled.</p>}<button type="button" className="integration-text-button" disabled={loading || !hasKey} onClick={onRefresh}>Refresh connection activity</button></div></li>
    </ol>
    <details className="integration-setup-check"><summary>Run a safe quote check — no SMS sent</summary>{connection && !connection.scopes.includes('sms:quote') && <p role="note">This connection does not have Calculate credits permission. Create a connection with that permission before running the quote example. No permissions are changed automatically.</p>}<ApiQuoteCheck key={connection?.id ?? 'no-connection'} instance="settings"/></details>
    <details className="integration-setup-check"><summary>After the quote: test one real SMS (optional)</summary><p>A real send uses credits and may reach a phone. Use one recipient who has agreed to the test. Review the quote, then run the send example on your own backend with a saved idempotency key. Store the returned request ID and follow its status; queued and accepted are not delivered.</p><ApiTermHelp term="idempotency"/><ApiTermHelp term="status"/><a href="/developers#examples" target="_blank" rel="noopener noreferrer">Open real-send examples and delivery instructions ↗</a><p>This page does not submit a test SMS or mark delivery as verified.</p></details>
    <div className="integration-doc-links"><a href="/developers#start" target="_blank" rel="noopener noreferrer">Full integration guide ↗</a><a href="/developers#glossary" target="_blank" rel="noopener noreferrer">API terms in plain language ↗</a></div>
  </div>;
}
