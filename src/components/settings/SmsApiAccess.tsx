import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BookOpen, ChevronRight, Copy, ExternalLink, KeyRound, Plus, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SMS_API_PRODUCTS, SMS_API_SCOPES, createSmsIntegration, getSmsIntegrations, issueSmsIntegrationKey, revokeSmsIntegration, type IntegrationCredential } from '@/lib/sms-integrations';
import { SmsApiSetup } from './SmsApiSetup';
import { ApiTermHelp } from './ApiIntegrationHelp';
import './api-integration-help.css';

const formatDate = (value: number) => new Date(Number(value) * 1000).toLocaleDateString();
const productLabel = (value: string) => ({ events: 'Events', 'tenant-management': 'Tenant Management', 'security-os': 'Security OS', website: 'Website', custom: 'Other software' }[value] ?? value);
export function SmsApiAccess() {
  const { user } = useAuth();
  const owner = user?.id;
  const status = useQuery({ queryKey: ['sms-api-access', owner], queryFn: getSmsIntegrations, enabled: !!owner, retry: false, staleTime: 0 });
  const [creating, setCreating] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [name, setName] = useState(''), [product, setProduct] = useState('custom'), [daily, setDaily] = useState('1000');
  const [scopes, setScopes] = useState<string[]>(['sms:quote', 'sms:send', 'sms:read']);
  const [secret, setSecret] = useState<{ owner: typeof owner; credential: IntegrationCredential } | null>(null);
  const [revoke, setRevoke] = useState<{ id: string; key?: string; label: string } | null>(null);
  useEffect(() => { setSecret(null); setCreating(false); setRevoke(null); setError(''); setNotice(''); }, [owner]);
  const perform = async (action: () => Promise<unknown>) => {
    setBusy(true); setError(''); setNotice('');
    try { await action(); await status.refetch(); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not update API access. Please retry.'); }
    finally { setBusy(false); }
  };
  const create = () => perform(async () => {
    const result = await createSmsIntegration({ name, product, scopes, daily_credit_limit: Number(daily), requests_per_minute: 60, expires_in_days: 90 });
    setSecret({ owner, credential: result.credential }); setCreating(false); setName('');
  });
  const ready = status.data?.available && status.data.can_issue_keys;
  const valid = name.trim().length > 0 && name.length <= 80 && /^\d+$/.test(daily) && Number(daily) >= 1 && Number(daily) <= 50000 && scopes.length > 0;
  return <section aria-labelledby="sms-api-heading" className="space-y-5">
    <div className="rounded-2xl border bg-card p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
<div className="flex gap-3">
<KeyRound className="mt-1 h-6 w-6 shrink-0 text-primary" aria-hidden="true"/>
<div>
<h3 id="sms-api-heading" className="text-lg font-semibold">Your API connections</h3>
<p className="mt-1 max-w-2xl text-sm text-muted-foreground">Manage access for your websites and applications. Messages use this account’s credits.</p>
</div>
</div>
<div className="flex gap-2">
<Button variant="outline" size="sm" aria-label="Refresh API connections" disabled={busy || status.isFetching} onClick={() => void status.refetch()}>
<RefreshCw className="h-4 w-4"/>
</Button>
<Button disabled={!ready || busy} onClick={() => { setError(''); setCreating(true); }} className="gap-2">
<Plus className="h-4 w-4"/>Add connection</Button>
</div>
</div>
      <div className="mt-4 flex gap-2 rounded-xl bg-primary/5 p-3 text-sm">
<ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"/>
<p>Keep keys only on your server. Never put them in browser JavaScript, mobile app code, public repositories or URLs. Keys cannot access other accounts or admin tools.</p>
</div>
      {status.isPending ? <div role="status" aria-label="Loading API connections" className="mt-5 space-y-3">
<div className="h-6 animate-pulse rounded bg-muted"/>
<div className="h-20 animate-pulse rounded-xl bg-muted"/>
</div> : status.isError ? <p role="alert" className="mt-4 text-sm">API connections could not be loaded. Use Refresh to try again.</p> : !status.data?.available ? <p className="mt-4 text-sm text-muted-foreground">API access is awaiting its database setup and activation. No external app can send yet.</p> : !status.data.can_issue_keys ? <p className="mt-4 text-sm">Before issuing a key, <Link className="text-primary underline" to="/settings?tab=security">enable the extra SMS Portal sign-in check</Link>, then sign out and back in. Existing keys can still be revoked here.</p> : null}
      <div className="mt-4 flex flex-wrap gap-4 text-xs"><a href="/developers" target="_blank" rel="noopener noreferrer" className="inline-flex min-h-9 items-center gap-2 text-primary">Developer guide<ExternalLink size={13} aria-hidden="true"/></a><a href="/sms-api-v1.openapi.json" target="_blank" rel="noopener noreferrer" className="inline-flex min-h-9 items-center gap-2 text-primary">OpenAPI specification<ExternalLink size={13} aria-hidden="true"/></a></div>
    </div>
    {error && <p role="alert" className="rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm">{error}</p>}
    {notice && <p role="status" className="rounded-xl border p-3 text-sm">{notice}</p>}
    {status.data?.available && !status.data.connections.length && <div className="rounded-2xl border border-dashed bg-card p-7 text-center">
<KeyRound className="mx-auto mb-3 h-8 w-8 text-muted-foreground" aria-hidden="true"/>
<h4 className="font-semibold">No API connections yet</h4>
<p className="mt-2 text-sm text-muted-foreground">Add a separate connection for each website or app so you can limit and revoke access independently.</p>
</div>}
    {status.data?.connections.map(app => <article key={app.id} className="space-y-4 rounded-2xl border bg-card p-5">
<div className="flex flex-wrap items-start justify-between gap-3">
<div className="min-w-0 flex-1">
<h4 className="font-semibold [overflow-wrap:anywhere]">{app.name}</h4>
<p className="text-sm text-muted-foreground">{productLabel(app.product)} · {app.status === 'active' ? 'Active' : 'Revoked'}</p>
</div>{app.status === 'active' && <div className="flex flex-wrap gap-2">
<Button variant="outline" size="sm" disabled={!ready || busy || app.keys.filter(key => !key.revoked_at && Number(key.expires_at) > Date.now() / 1000).length >= 2} onClick={() => void perform(async () => { const result = await issueSmsIntegrationKey(app.id); setSecret({ owner, credential: result.credential }); })}>Add replacement key</Button>
<Button variant="outline" size="sm" disabled={busy} onClick={() => setRevoke({ id: app.id, label: app.name })}>Revoke connection</Button>
</div>}</div>
<div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
<span>Daily allowance: {Number(app.admitted_credits_today).toLocaleString()} / {Number(app.daily_credit_limit).toLocaleString()} credits</span>
</div>
<details className="settings-api-key-details"><summary>Keys & permissions ({app.keys.length})</summary><div><p className="mb-2 break-words text-xs text-muted-foreground">Permissions: {app.scopes.join(', ')} · {app.requests_per_minute} API requests/minute</p>
<ul className="divide-y rounded-xl border px-3">{app.keys.map(key => <li key={key.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
<div>
<code>smsk_{key.id.slice(0, 8)}…</code>
<p className="text-xs text-muted-foreground">{key.revoked_at ? 'Revoked' : Number(key.expires_at) <= Date.now() / 1000 ? 'Expired' : `Expires ${formatDate(key.expires_at)}`} · {key.last_used_at ? `Last used ${formatDate(key.last_used_at)}` : 'Not used yet'}</p>
</div>{!key.revoked_at && <Button variant="ghost" size="sm" aria-label={`Revoke key ${key.id.slice(0, 8)}`} disabled={busy} onClick={() => setRevoke({ id: app.id, key: key.id, label: `key smsk_${key.id.slice(0, 8)}…` })}>
<Trash2 className="mr-1 h-4 w-4"/>Revoke</Button>}</li>)}</ul></div></details>
</article>)}
    <details className="settings-api-guide"><summary><BookOpen size={20} aria-hidden="true"/><span>Need help connecting an app?<small>Open the guided setup and safe connection check.</small></span><ChevronRight size={17} aria-hidden="true"/></summary><SmsApiSetup key={owner} status={status.data} loading={status.isPending || status.isFetching || busy} onCreate={() => { setError(''); setCreating(true); }} onRefresh={() => void status.refetch()}/></details>
    <Dialog open={creating} onOpenChange={value => { if (!busy) setCreating(value); }}>
<DialogContent className="max-h-[90dvh] overflow-y-auto">
<DialogHeader>
<DialogTitle>Add API connection</DialogTitle>
<DialogDescription>This authorizes the connected app to use your credits. The secret key is shown once.</DialogDescription>
</DialogHeader>{error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <form className="space-y-4" onSubmit={event => { event.preventDefault(); void create(); }}>
        <div>
<Label htmlFor="api-connection-name">Connection name</Label>
<Input id="api-connection-name" maxLength={80} value={name} onChange={event => setName(event.target.value)} placeholder="Events production" required/>
<ApiTermHelp term="connection"/>
</div>
        <div>
<Label htmlFor="api-connection-product">Product</Label>
<select id="api-connection-product" className="mt-1 w-full rounded-lg border bg-background p-2" value={product} onChange={event => setProduct(event.target.value)}>{SMS_API_PRODUCTS.map(value => <option key={value} value={value}>{{ events: 'Events', 'tenant-management': 'Tenant Management', 'security-os': 'Security OS', website: 'Website', custom: 'Other / custom software' }[value]}</option>)}</select>
<ApiTermHelp term="product"/>
</div>
        <div>
<Label htmlFor="api-daily-credits">Daily credit allowance</Label>
<Input id="api-daily-credits" inputMode="numeric" value={daily} onChange={event => setDaily(event.target.value)} required/>
<p className="mt-1 text-xs text-muted-foreground">1–50,000 credits per day, subject to your account’s balance and overall limits.</p>
<ApiTermHelp term="allowance"/>
</div>
        <fieldset className="space-y-2">
<legend className="mb-2 text-sm font-medium">Allowed permissions</legend>
<ApiTermHelp term="permissions"/>{SMS_API_SCOPES.map(([scope, label]) => <label key={scope} className="flex items-center gap-2 text-sm">
<input type="checkbox" checked={scopes.includes(scope)} onChange={event => setScopes(previous => event.target.checked ? [...previous, scope] : previous.filter(value => value !== scope))}/>{label}</label>)}</fieldset>
        <DialogFooter className="sticky bottom-0 border-t bg-background pt-3">
<Button type="button" variant="outline" disabled={busy} onClick={() => setCreating(false)}>Back</Button>
<Button type="submit" disabled={busy || !valid}>{busy ? 'Creating…' : 'Create connection and key'}</Button>
</DialogFooter>
      </form>
</DialogContent>
</Dialog>
    <Dialog open={!!secret && secret.owner === owner} onOpenChange={open => { if (!open) setSecret(null); }}>
<DialogContent>
<DialogHeader>
<DialogTitle>Save your server-side key</DialogTitle>
<DialogDescription>This key will not be shown again. Store it securely on your application’s backend, not in browser code.</DialogDescription>
</DialogHeader>{secret?.owner === owner && <>
<Label htmlFor="sms-api-secret">New API key</Label>
<textarea id="sms-api-secret" className="w-full resize-none break-all rounded-lg border bg-muted p-3 font-mono text-xs" rows={4} readOnly autoComplete="off" spellCheck={false} value={secret?.credential.token ?? ''}/>
<Button variant="outline" onClick={() => void navigator.clipboard.writeText(secret?.credential.token ?? '').then(() => setNotice('Key copied. Keep it private.')).catch(() => setError('Copy is unavailable. Select and copy the key manually.'))}>
<Copy className="mr-2 h-4 w-4"/>Copy key</Button>
</>}<DialogFooter>
<Button onClick={() => setSecret(null)}>I have saved the key</Button>
</DialogFooter>
</DialogContent>
</Dialog>
    <Dialog open={!!revoke} onOpenChange={open => { if (!busy && !open) setRevoke(null); }}>
<DialogContent>
<DialogHeader>
<DialogTitle>Revoke API access?</DialogTitle>
<DialogDescription>Revoke {revoke?.label}? This blocks future API requests using that access. Already queued messages are not cancelled.</DialogDescription>
</DialogHeader>
<DialogFooter>
<Button variant="outline" disabled={busy} onClick={() => setRevoke(null)}>Keep access</Button>
<Button variant="destructive" disabled={busy} onClick={() => void perform(async () => { if (revoke) await revokeSmsIntegration(revoke.id, revoke.key); setRevoke(null); setNotice('API access revoked.'); })}>Revoke access</Button>
</DialogFooter>
</DialogContent>
</Dialog>
  </section>;
}
