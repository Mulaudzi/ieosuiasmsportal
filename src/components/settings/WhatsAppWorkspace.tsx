import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FileText, MessageCircle, RefreshCw, Send, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { getWhatsAppTemplates, getWhatsAppWorkspace, submitWhatsAppOperation, type WhatsAppTemplate } from '@/lib/whatsapp-workspace';
import type { WhatsAppConnection } from '@/lib/whatsapp-connect';
import './whatsapp-workspace.css';

export function WhatsAppWorkspace({ connections }: { connections: WhatsAppConnection[] }) {
  const { user } = useAuth();
  const owner = user?.id;
  const [phone, setPhone] = useState('');
  const active = connections.filter(item => item.status === 'authorized');
  const selectedPhone = active.some(item => item.phone_number_id === phone) ? phone : active[0]?.phone_number_id ?? '';
  const status = useQuery({ queryKey: ['whatsapp-workspace', owner], queryFn: getWhatsAppWorkspace, enabled: !!owner, retry: false, refetchInterval: 15000 });
  const templates = useQuery({ queryKey: ['whatsapp-templates', owner, selectedPhone], queryFn: () => getWhatsAppTemplates(selectedPhone), enabled: !!owner && !!selectedPhone && status.data?.available === true, retry: false, staleTime: 0 });
  const [tab, setTab] = useState<'templates' | 'send' | 'activity'>('templates');
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [name, setName] = useState(''), [text, setText] = useState(''), [category, setCategory] = useState('UTILITY'), [language, setLanguage] = useState('en_ZA');
  const [recipient, setRecipient] = useState(''), [templateKey, setTemplateKey] = useState(''), [parameters, setParameters] = useState<string[]>([]);
  const [consent, setConsent] = useState(false), [consentNote, setConsentNote] = useState(''), [reviewing, setReviewing] = useState(false);
  const [locked, setLocked] = useState(false);
  const running = useRef(false);
  const template = templates.data?.templates.find(item => `${item.name}:${item.language}` === templateKey);
  useEffect(() => { setTemplateKey(''); setParameters([]); setReviewing(false); setConsent(false); setError(''); setNotice(''); }, [selectedPhone, owner]);
  if (!status.data?.available) return status.isError ? <div className="wa-workspace" role="alert"><p>WhatsApp tools could not be loaded.</p><Button variant="outline" onClick={() => void status.refetch()}>Retry WhatsApp tools</Button></div> : null;
  const submit = async (action: 'send' | 'create-template' | 'subscribe', body: Record<string, unknown>) => {
    if (running.current) return;
    running.current = true; setBusy(true); setError(''); setNotice('');
    if (action === 'send') setLocked(true);
    try {
      const result = await submitWhatsAppOperation(action, { ...body, phone_number_id: selectedPhone, operation_key: crypto.randomUUID() });
      if (result.outcome === 'accepted') {
        setNotice(action === 'send' ? 'Meta accepted the message. Check Recent activity for delivery and read updates.' : action === 'create-template' ? 'Meta received your template. Refresh the list to check its review status.' : 'Meta accepted the message update subscription.');
        if (action === 'create-template') { setName(''); setText(''); }
      } else if (result.outcome === 'rejected') {
        setError('Meta rejected this request. Check the account, template and permissions before starting a new submission.');
      } else setError('The submission outcome is unconfirmed. Check Recent activity and Meta before starting a new submission. This request will not be retried automatically.');
      if (action !== 'subscribe') setTab('activity');
    } catch (e) {
      setError((e instanceof Error ? e.message : 'The request could not be confirmed.') + ' Check Recent activity before repeating a submission.');
    } finally {
      running.current = false; setBusy(false); setReviewing(false);
      void status.refetch(); void templates.refetch();
    }
  };
  const canReview = !!template && template.status === 'APPROVED' && template.supported && /^\+27[1-9]\d{8}$/.test(recipient) && consent && consentNote.trim().length >= 5 && parameters.length === template.parameter_count && parameters.every(value => value.trim()) && status.data.sending_enabled && !locked && !busy;
  const preview = (item?: WhatsAppTemplate) => (item?.components.find(component => component.type === 'BODY')?.text ?? '').replace(/\{\{(\d+)\}\}/g, (match, index) => parameters[Number(index) - 1] || match);
  return <section className="wa-workspace" aria-labelledby="wa-workspace-heading">
    <header className="wa-workspace-heading"><div><span className="wa-eyebrow">WHATSAPP BUSINESS</span><h3 id="wa-workspace-heading">Messages & templates</h3><p>Use your connected business number to reach customers who have opted in. The pilot supports South African numbers.</p></div><MessageCircle aria-hidden="true"/></header>
    {error && <p role="alert" className="wa-alert">{error}</p>}{notice && <p role="status" className="wa-notice">{notice}</p>}
    {!active.length ? <p className="wa-empty">Connect and confirm your business number above to manage templates and send messages.</p> : <>
      <div className="wa-sender"><label htmlFor="wa-sender">Sending business number<select id="wa-sender" value={selectedPhone} disabled={busy} onChange={event => setPhone(event.target.value)}>{active.map(item => <option key={item.phone_number_id} value={item.phone_number_id}>{item.display_phone_number} — {item.verified_name || item.business_name}</option>)}</select></label><Button variant="outline" disabled={busy} onClick={() => void submit('subscribe', {})}>Enable message updates</Button></div>
      <nav className="wa-tabs" aria-label="WhatsApp tools">{([['templates', 'Templates'], ['send', 'Send message'], ['activity', 'Recent activity']] as const).map(([value, label]) => <button key={value} type="button" aria-current={tab === value ? 'page' : undefined} onClick={() => { setTab(value); setReviewing(false); }}>{label}</button>)}</nav>
      {tab === 'templates' && <div className="wa-columns"><section className="wa-card"><div className="wa-card-heading"><h4><FileText size={18} aria-hidden="true"/> Your Meta templates</h4><Button variant="outline" size="sm" disabled={templates.isFetching || busy} onClick={() => void templates.refetch()}><RefreshCw size={14} aria-hidden="true"/> Refresh</Button></div>
        {templates.isPending ? <p role="status">Loading templates from Meta…</p> : templates.isError ? <p role="alert">Templates could not be loaded. Check the connection and refresh.</p> : !templates.data?.templates.length ? <p>No templates found. Create your first template here.</p> : <ul className="wa-template-list">{templates.data.templates.map(item => <li key={`${item.name}:${item.language}`}><div><strong>{item.name}</strong><span>{item.status}</span></div><small>{item.category} · {item.language}</small><p>{item.components.find(component => component.type === 'BODY')?.text}</p>{!item.supported && <small>This template uses features outside the text-message pilot.</small>}</li>)}</ul>}
      </section><form className="wa-card" onSubmit={event => { event.preventDefault(); void submit('create-template', { name, text, category, language }); }}><h4>Create a text template</h4><p>Meta reviews each template before it can be used. Choose the category that matches its purpose.</p><fieldset disabled={busy}>
        <label htmlFor="wa-template-name">Template name<input id="wa-template-name" required pattern="[a-z][a-z0-9_]*" maxLength={512} value={name} onChange={event => setName(event.target.value)} placeholder="requested_order_update"/><small>Lowercase letters, numbers and underscores.</small></label>
        <label htmlFor="wa-category">Category<select id="wa-category" value={category} onChange={event => setCategory(event.target.value)}><option value="UTILITY">Utility — requested or transactional updates</option><option value="MARKETING">Marketing — promotions and announcements</option></select></label>
        <label htmlFor="wa-language">Language<select id="wa-language" value={language} onChange={event => setLanguage(event.target.value)}><option value="en_ZA">English (South Africa)</option><option value="en_US">English (US)</option><option value="en_GB">English (UK)</option></select></label>
        <label htmlFor="wa-template-body">Message body<textarea id="wa-template-body" required rows={5} maxLength={1024} value={text} onChange={event => setText(event.target.value)}/><small>{text.length}/1,024 characters. Create plain text templates without variables in this pilot.</small></label>
        <Button type="submit" disabled={busy || !text.trim() || text.includes('{{')}>Submit template to Meta</Button>
      </fieldset></form></div>}
      {tab === 'send' && <div className="wa-columns"><form className="wa-card" onSubmit={event => { event.preventDefault(); if (canReview) setReviewing(true); }}><h4><Send size={18} aria-hidden="true"/> Send an approved template</h4><p>Send one message to a South African recipient. Meta messaging charges may apply. SMS credits are separate.</p>{!status.data.sending_enabled && <p role="status" className="wa-notice">Sending is awaiting activation.</p>}<fieldset disabled={busy || locked || reviewing}>
        <label htmlFor="wa-recipient">Recipient WhatsApp number<input id="wa-recipient" type="tel" required pattern="\+27[1-9][0-9]{8}" placeholder="+27…" value={recipient} onChange={event => setRecipient(event.target.value)}/></label>
        <label htmlFor="wa-approved-template">Approved template<select id="wa-approved-template" required value={templateKey} onChange={event => { setTemplateKey(event.target.value); const item = templates.data?.templates.find(t => `${t.name}:${t.language}` === event.target.value); setParameters(Array(item?.parameter_count ?? 0).fill('')); }}><option value="">Choose a template</option>{templates.data?.templates.filter(item => item.status === 'APPROVED' && item.supported).map(item => <option key={`${item.name}:${item.language}`} value={`${item.name}:${item.language}`}>{item.name} ({item.language})</option>)}</select></label>
        {templates.isError && <p role="alert">Templates could not be loaded. Open Templates and refresh.</p>}
        {parameters.map((value, index) => <label key={index} htmlFor={`wa-param-${index}`}>Parameter {index + 1}<input id={`wa-param-${index}`} required maxLength={1024} value={value} onChange={event => setParameters(previous => previous.map((item, i) => i === index ? event.target.value : item))}/></label>)}
        <label className="wa-checkbox"><input type="checkbox" checked={consent} onChange={event => setConsent(event.target.checked)}/><span>I confirm this recipient opted in to WhatsApp messages from this business and has not opted out.</span></label>
        <label htmlFor="wa-consent-note">How was permission obtained?<textarea id="wa-consent-note" required minLength={5} maxLength={500} rows={2} value={consentNote} onChange={event => setConsentNote(event.target.value)} placeholder="For example, a recorded request to receive this update."/></label>
        <Button type="submit" disabled={!canReview}>Review message</Button>
      </fieldset>{locked && <div className="wa-new-message"><p>Check Recent activity before composing another message.</p><Button type="button" variant="outline" disabled={busy} onClick={() => { setLocked(false); setConsent(false); setConsentNote(''); setRecipient(''); setNotice(''); setError(''); }}>Compose another message</Button></div>}</form>
        <section className="wa-card"><h4>Message preview</h4><p className="wa-preview">{preview(template) || 'Choose an approved template to preview your message.'}</p>{reviewing && <div className="wa-confirm"><h4><ShieldCheck size={18} aria-hidden="true"/> Confirm this message</h4><p>From: {active.find(item => item.phone_number_id === selectedPhone)?.display_phone_number}</p><p>To: <strong>{recipient}</strong></p><p>Template: {template?.name} · {template?.language}</p><p>Meta charges may apply. Sending does not confirm delivery.</p><div className="wa-actions"><Button type="button" variant="outline" disabled={busy} onClick={() => setReviewing(false)}>Back to editing</Button><Button type="button" disabled={busy || !canReview} onClick={() => void submit('send', { recipient, template: template?.name, language: template?.language, parameters, consent, consent_note: consentNote })}>Send WhatsApp message</Button></div></div>}</section>
      </div>}
      {tab === 'activity' && <section className="wa-card"><div className="wa-card-heading"><h4>Recent activity</h4><Button variant="outline" size="sm" disabled={status.isFetching || busy} onClick={() => void status.refetch()}>Refresh activity</Button></div><p>Accepted means Meta received the request. Delivered and read appear only when confirmed by signed Meta updates. Unconfirmed submissions are never retried automatically.</p>{!status.data.operations.length ? <p>No WhatsApp activity yet.</p> : <ul className="wa-activity">{status.data.operations.map(item => <li key={item.key}><div><strong>{item.kind === 'send' ? 'Message' : item.kind === 'template' ? 'Template submission' : 'Message updates'}</strong><span>{item.state}</span></div><p>{item.template}{item.recipient ? ` → ${item.recipient}` : ''}</p><small>{new Date(item.created_at * 1000).toLocaleString('en-ZA', { timeZone: 'Africa/Johannesburg' })} SAST</small>{item.provider_id && <small className="wa-provider-id">Meta reference: {item.provider_id}</small>}</li>)}</ul>}</section>}
    </>}
  </section>;
}
