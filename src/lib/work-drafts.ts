import {api} from '@/lib/api';
export type DraftKind = 'sms' | 'purchase';
export interface SmsDraftPayload {
  version: 1; step: number; name: string; manual: string; groupIds: string[]; contactIds: string[];
  excluded: string[]; selectedTemplate: string; content: string; includeOptOut: boolean; schedule: string; submissionKey: string;
}
export interface PurchaseDraftPayload { version: 1; step: 'order' | 'payment'; credits: number; method: 'payos' | 'eft'; requestKey: string }
export interface DraftPayloads {sms: SmsDraftPayload; purchase: PurchaseDraftPayload}
export interface DraftReference {id: string; title: string; version: number}
export const draftReference = ({id, title, version}: DraftReference): DraftReference => ({id, title, version});
export interface WorkDraft<K extends DraftKind = DraftKind> extends DraftReference {
  kind: K; payload: DraftPayloads[K]; created_at: string; updated_at: string;
}
export type DraftSummary = Omit<WorkDraft, 'payload'>;
export const draftKey = (userId: string, kind: DraftKind) => ['work-drafts', userId, kind];
export function isSmsDraft(value: unknown): value is SmsDraftPayload {
  if (!value || typeof value !== 'object') return false;
  const d = value as Record<string, unknown>;
  return d.version === 1 && Number.isInteger(d.step) && Number(d.step) >= 1 && Number(d.step) <= 5 && typeof d.includeOptOut === 'boolean'
    && ['name', 'manual', 'selectedTemplate', 'content', 'schedule', 'submissionKey'].every(k => typeof d[k] === 'string')
    && ['groupIds', 'contactIds', 'excluded'].every(k => Array.isArray(d[k]) && (d[k] as unknown[]).every(v => typeof v === 'string'))
    && /^[a-zA-Z0-9-]{1,80}$/.test(String(d.submissionKey));
}
export function isPurchaseDraft(value: unknown): value is PurchaseDraftPayload {
  if (!value || typeof value !== 'object') return false;
  const d = value as PurchaseDraftPayload;
  return d.version === 1 && ['order', 'payment'].includes(d.step) && ['payos', 'eft'].includes(d.method)
    && Number.isSafeInteger(d.credits) && d.credits >= 0 && d.credits <= 1000000 && typeof d.requestKey === 'string' && /^[a-zA-Z0-9-]{1,80}$/.test(d.requestKey);
}
function unpack<K extends DraftKind>(value: unknown, kind: K): WorkDraft<K> {
  const d = value as WorkDraft<K>;
  if (!d || d.kind !== kind || !d.id || !Number.isInteger(d.version) || !(kind === 'sms' ? isSmsDraft(d.payload) : isPurchaseDraft(d.payload))) throw new Error('This draft could not be opened. Return to your saved drafts and try again.');
  return d;
}
export async function getWorkDraft<K extends DraftKind>(id: string, kind: K): Promise<WorkDraft<K>> {
  const r = await api.get<{draft: WorkDraft<K>}>(`/drafts/${encodeURIComponent(id)}`);
  return unpack(r.draft ?? r.data?.draft, kind);
}
export async function saveWorkDraft<K extends DraftKind>(id: string, kind: K, title: string, payload: DraftPayloads[K], expectedVersion: number): Promise<WorkDraft<K>> {
  const r = await api.put<{draft: WorkDraft<K>}>(`/drafts/${id}`, {kind, title, payload, expected_version: expectedVersion});
  return unpack(r.draft ?? r.data?.draft, kind);
}
export async function deleteWorkDraft(ref: DraftReference) {
  return api.delete(`/drafts/${encodeURIComponent(ref.id)}`, {data: {expected_version: ref.version}});
}
export function readPurchaseRecovery(userId: string): {payload: PurchaseDraftPayload; reference?: DraftReference} | null {
  try {
    const d = JSON.parse(localStorage.getItem(`sms-purchase-draft:v1:${userId}`) || 'null');
    if (!d || !isPurchaseDraft(d.payload)) return null;
    const r = d.reference;
    return {payload: d.payload, ...(r && typeof r.id === 'string' && typeof r.title === 'string' && Number.isInteger(r.version) && r.version > 0 ? {reference: draftReference(r)} : {})};
  } catch {return null;}
}
