import {useState} from 'react';
import {Link} from 'react-router-dom';
import {useQuery, useQueryClient} from '@tanstack/react-query';
import {useAuth} from '@/hooks/useAuth';
import {api} from '@/lib/api';
import {deleteWorkDraft, DraftKind, draftKey, DraftSummary} from '@/lib/work-drafts';
import {Button} from '@/components/ui/button';
export function DraftList({kind}: {kind: DraftKind}) {
  const {user} = useAuth();const client = useQueryClient();const [deleting, setDeleting] = useState<string | null>(null), [error, setError] = useState('');
  const query = useQuery({queryKey: draftKey(String(user?.id ?? ''), kind), enabled: !!user, retry: false, queryFn: async () => {
    const r = await api.get<{drafts: DraftSummary[]}>('/drafts', {kind});return (r.drafts ?? r.data?.drafts ?? []) as DraftSummary[];
  }});
  const remove = async (draft: DraftSummary) => {
    if (!window.confirm(`Delete draft "${draft.title}"? This does not change any campaign, order or payment.`)) return;
    setDeleting(draft.id);setError('');
    try {await deleteWorkDraft(draft);await client.invalidateQueries({queryKey: draftKey(String(user?.id), kind)});}
    catch (e) {setError(e instanceof Error ? e.message : 'Could not delete this draft.');void query.refetch();}
    finally {setDeleting(null);}
  };
  if (!user) return null;
  return <section aria-label={kind === 'sms' ? 'Saved SMS drafts' : 'Saved purchase drafts'} className="my-6 rounded-xl border bg-card p-4 space-y-3">
    <div><h2 className="font-semibold">{kind === 'sms' ? 'Saved SMS drafts' : 'Saved purchase drafts'}</h2><p className="text-sm text-muted-foreground">Continue where you stopped. Drafts do not send messages, take payment or use credits.</p></div>
    {query.isPending ? <p role="status" className="text-sm">Loading drafts...</p> : query.isError ? <p role="alert" className="text-sm">Could not load account drafts. <Button variant="link" size="sm" onClick={() => void query.refetch()}>Retry</Button></p> : query.data?.length ? <ul className="divide-y">{query.data.map(d => <li key={d.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"><div className="min-w-0"><p className="break-words font-medium">{d.title}</p><p className="text-xs text-muted-foreground">Saved {d.updated_at}</p></div><div className="flex shrink-0 gap-2"><Button asChild size="sm" variant="outline"><Link to={`${kind === 'sms' ? '/sms-campaigns/new' : '/wallet'}?draft=${encodeURIComponent(d.id)}`}>Resume</Link></Button><Button size="sm" variant="ghost" disabled={deleting !== null} onClick={() => void remove(d)}>{deleting === d.id ? 'Deleting...' : 'Delete'}</Button></div></li>)}</ul> : <p className="text-sm text-muted-foreground">No saved drafts yet. Use Save draft while {kind === 'sms' ? 'creating a campaign' : 'choosing your SMS credits'}.</p>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
  </section>;
}
