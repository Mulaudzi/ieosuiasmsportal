/** Presentation only. Confirmed = accepted + excluded + failed submissions, never delivery reports. */
export interface SendingProgressInput {
  total: number; sent: number; excluded?: number; failed?: number; held?: number;
  state?: string; processing?: number;
}
const count = (value: number | undefined) => Number.isFinite(value) ? Math.max(0, Math.floor(value!)) : 0;
export function sendingProgress(input: SendingProgressInput) {
  const total = count(input.total), sent = count(input.sent), excluded = count(input.excluded), failed = count(input.failed);
  const processed = Math.min(total, sent + excluded + failed);
  const waiting = Math.max(0, total - processed), held = Math.min(waiting, count(input.held));
  const state = (input.state ?? '').toLowerCase();
  const percent = total ? Math.min(waiting > 0 ? 99 : 100, Math.round(processed / total * 100)) : 0;
  const kind = total === 0 ? 'empty' : state === 'draft' ? 'draft' : state === 'cancelled' ? 'cancelled'
    : waiting === 0 ? failed > 0 || sent === 0 ? 'issues' : 'complete'
    : state === 'scheduled' ? 'scheduled' : waiting === held ? 'held'
    : ['completed','partially_failed','failed','sent','delivered'].includes(state) ? 'reconciling'
    : ['queued','pending'].includes(state) && processed === 0 && !count(input.processing) ? 'queued' : 'sending';
  // A visual waiting phase, not a fabricated server state or evidence of submission.
  const preparing=(kind==='queued'||kind==='sending')&&sent===0&&failed===0&&held===0;
  return {total,sent,excluded,failed,processed,waiting,held,waitingToSend:waiting-held,percent,kind,preparing,
    active:kind==='sending', finished:kind==='complete'||kind==='issues',
    phase:kind==='empty'||kind==='draft'?0:kind==='complete'||kind==='issues'?3:kind==='sending'||kind==='held'||kind==='reconciling'||(kind==='cancelled'&&processed>0)?2:1};
}
