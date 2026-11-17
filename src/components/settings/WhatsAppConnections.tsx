import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { MessageCircle, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/hooks/useAuth';
import { getWhatsAppConnections, startWhatsAppConnection, confirmWhatsAppConnection,
  cancelWhatsAppConnection, disconnectWhatsAppConnection } from '@/lib/whatsapp-connect';
import { WhatsAppWorkspace } from './WhatsAppWorkspace';

export function WhatsAppConnections() {
  const { user } = useAuth();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [selected, setSelected] = useState('');
  const [disconnecting, setDisconnecting] = useState<string | null>(null);
  const status = useQuery({ queryKey: ['whatsapp-connections', user?.id], queryFn: getWhatsAppConnections,
    enabled: !!user?.id, staleTime: 0, retry: false });
  const run = async (action: () => Promise<unknown>, message = '') => {
    setBusy(true); setError(''); setNotice('');
    try { await action(); setNotice(message); setSelected(''); setDisconnecting(null); await status.refetch(); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not update your WhatsApp connection.'); }
    finally { setBusy(false); }
  };
  const pending = status.data?.pending;
  const phone = pending?.options.some(option => option.phone_number_id === selected) ? selected : '';
  return <><section aria-labelledby="whatsapp-connections-title" className="space-y-5 rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
    <div className="flex items-start gap-3"><MessageCircle className="mt-1 h-6 w-6 shrink-0 text-primary" aria-hidden="true"/>
      <div><h3 id="whatsapp-connections-title" className="text-lg font-semibold">Your WhatsApp Business numbers</h3>
        <p className="mt-1 text-sm text-muted-foreground">Connect your business through Meta and choose the number to link to this portal account.</p></div></div>
    <p className="text-sm text-muted-foreground">Linking a number does not send messages. Approved pilot accounts can manage templates and send individual messages below.</p>
    {status.isPending ? <p role="status">Loading WhatsApp connections...</p> : status.isError ?
      <div role="alert"><p>WhatsApp connections could not be loaded.</p><Button variant="outline" onClick={() => void status.refetch()}>Retry</Button></div> :
      !status.data?.available ? <p className="text-sm text-muted-foreground">Customer WhatsApp connections are awaiting setup. Please check back later.</p> : <>
        {pending && <div className="space-y-3 rounded-xl border bg-muted/20 p-4">
          <h4 className="font-medium">Confirm your business number</h4>
          <p className="text-sm text-muted-foreground">Meta authorized the numbers below. Choose the one to link to your account, then confirm. This approval expires in a few minutes.</p>
          <label htmlFor="whatsapp-number" className="block text-sm font-medium">Business number</label>
          <select id="whatsapp-number" value={phone} disabled={busy} onChange={event => setSelected(event.target.value)} className="h-11 w-full rounded-md border bg-background px-3 text-sm">
            <option value="">Choose a number</option>
            {pending.options.map(option => <option key={option.phone_number_id} value={option.phone_number_id}>
              {option.display_phone_number} — {option.verified_name || option.business_name}
            </option>)}
          </select>
          <div className="flex flex-wrap gap-2"><Button disabled={busy || !phone} onClick={() => void run(() => confirmWhatsAppConnection(pending.attempt, phone), 'WhatsApp number linked. Sending is still awaiting setup.')}>
            Confirm connection</Button><Button variant="outline" disabled={busy} onClick={() => void run(cancelWhatsAppConnection, 'Connection attempt cancelled.')}>Cancel connection</Button></div>
        </div>}
        {status.data.connections.length > 0 && <ul className="space-y-3" aria-label="Your WhatsApp connections">
          {status.data.connections.map(connection => <li key={connection.phone_number_id} className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2"><div><p className="font-medium">{connection.display_phone_number}</p>
              <p className="text-sm text-muted-foreground">{connection.verified_name || connection.business_name}</p></div>
              <span className="rounded-full bg-muted px-3 py-1 text-xs">{connection.status === 'authorized' ? 'Linked for setup' : connection.status === 'expired' ? 'Reconnect required' : 'Disconnected'}</span></div>
            {connection.status === 'authorized' && connection.expires_at !== null && <p className="text-xs text-muted-foreground">Authorization expires {new Date(connection.expires_at * 1000).toLocaleDateString()}.</p>}
            {connection.status !== 'disconnected' && (disconnecting === connection.phone_number_id ? <div className="space-y-2">
              <p className="text-sm">Disconnect this number from the portal? This removes the saved credentials. Your WhatsApp Business app account stays in place.</p>
              <div className="flex flex-wrap gap-2"><Button variant="destructive" disabled={busy} onClick={() => void run(() => disconnectWhatsAppConnection(connection.phone_number_id), 'WhatsApp number disconnected from the portal.')}>
                Confirm disconnect</Button><Button variant="outline" disabled={busy} onClick={() => setDisconnecting(null)}>Keep connection</Button></div>
            </div> : <Button variant="outline" disabled={busy} onClick={() => setDisconnecting(connection.phone_number_id)}>Disconnect {connection.display_phone_number}</Button>)}
          </li>)}
        </ul>}
        <Button disabled={busy || !!pending} onClick={() => void run(startWhatsAppConnection)}>
          {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true"/>}Connect WhatsApp Business</Button>
        <p className="text-xs text-muted-foreground">Continue in this browser and sign in to Meta as an administrator of your business. You can review the number before saving the connection.</p>
      </>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {notice && <p role="status" className="text-sm">{notice}</p>}
  </section>{status.data?.available && <WhatsAppWorkspace key={user?.id} connections={status.data.connections}/>}</>;
}
