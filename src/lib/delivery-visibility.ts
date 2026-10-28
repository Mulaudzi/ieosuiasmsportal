/** Display readiness only. Never changes or infers a recipient's delivery outcome. */
export function deliveryVisibility(counts: readonly number[], total: number, failedSubmissions=0) {
  const valid = Number.isSafeInteger(total) && total >= 0 && Number.isSafeInteger(failedSubmissions) && failedSubmissions>=0 && counts.length === 5
    && counts.every(value => Number.isSafeInteger(value) && value >= 0);
  const [delivered = 0, awaiting = 0, unavailable = 0, failed = 0, skipped = 0] = counts;
  const accounted = delivered + awaiting + unavailable + failed + skipped;
  const waitingToSend = valid ? Math.max(0, total - accounted - failedSubmissions) : 0;
  const unconfirmed = awaiting + unavailable;
  const complete = valid && total > 0 && accounted + failedSubmissions === total && unconfirmed === 0;
  const available = valid && accounted > 0 && accounted + failedSubmissions<=total;
  const empty = valid && total === 0 && accounted === 0;
  const title = empty ? 'No delivery results yet'
    : waitingToSend > 0 ? 'Waiting for sending to finish'
    : awaiting > 0 ? 'Delivery confirmations are updating'
    : unavailable > 0 ? 'Delivery confirmation unavailable'
    : available ? 'Delivery information updated' : 'No messages were successfully sent';
  const message = empty ? 'Send a campaign to see delivery results.'
    : waitingToSend > 0 ? 'Some messages are still waiting to be sent. Confirmations for submitted messages update as network reports arrive.'
    : awaiting > 0 ? "Your messages have already been sent for delivery. We're receiving delivery confirmations from the mobile networks as they become available. No action is required."
    : unavailable > 0 ? 'Your SMS was successfully sent for delivery, but the mobile network has not provided a conclusive delivery result. This does not necessarily mean that the message was not delivered.'
    : available ? "We've received delivery information from the mobile networks. Your campaign report reflects the latest available confirmations."
    : 'Review the excluded recipients and failed submissions. They are not network delivery failures.';
  return {complete, available, empty, waitingToSend, unconfirmed, title, message};
}

export interface DashboardDeliverySummary {
  total_sent: number;
  total_delivered: number;
  total_failed: number;
  total_pending: number;
  total_awaiting_delivery: number;
  total_dlr_unavailable: number;
  total_skipped: number;
  delivery_rate: number;
  total_recipients?:number;
  total_waiting_to_send?:number;
  total_failed_submissions?:number;
}

export function dashboardDelivery(summary: DashboardDeliverySummary) {
  const counts = [summary.total_delivered, summary.total_awaiting_delivery,
    summary.total_dlr_unavailable, summary.total_failed, summary.total_skipped].map(Number);
  // The canonical period total excludes skipped recipients and includes dispatch-pending rows.
  const total = Number(summary.total_recipients ?? (Number(summary.total_sent) + Number(summary.total_skipped)));
  return {counts, total, ...deliveryVisibility(counts, total,summary.total_failed_submissions??0)};
}
