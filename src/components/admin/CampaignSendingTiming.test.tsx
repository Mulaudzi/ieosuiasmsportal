import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { CampaignSendingTiming, CustomerSendingSummary, SendingTimeCell, sendingDuration, type SendingTiming } from './CampaignSendingTiming';

afterEach(cleanup);
const timing: SendingTiming = { status: 'complete', source: 'accepted_timestamps', queue_wait_seconds: 46, sending_seconds: 1309, total_seconds: 1355, elapsed_seconds: null, accepted_per_minute: 22.05, accepted_count: 481, outstanding_count: 0, held_count: 0, skipped_count: 4 };
it('shows measured sending time separately from queue and delivery', () => {
  render(<CampaignSendingTiming timing={timing} />);
  expect(screen.getByText('21m 49s')).toBeInTheDocument();
  expect(screen.getByText('22m 35s')).toBeInTheDocument();
  expect(screen.getByText('46s')).toBeInTheDocument();
  expect(screen.getByText('22.05 SMS/min')).toBeInTheDocument();
  expect(screen.getByText(/481 accepted.*4 skipped/)).toBeInTheDocument();
  expect(screen.getByText(/not handset delivery or later administrative completion/)).toBeInTheDocument();
});
it('does not present an unfinished acceptance window as a finished sending duration', () => {
  render(<SendingTimeCell timing={{ ...timing, status: 'held', elapsed_seconds: 1800 }} />);
  expect(screen.getByText('30m 0s elapsed')).toBeInTheDocument();
  expect(screen.getByText('Held for reconciliation')).toBeInTheDocument();
  expect(screen.queryByText('21m 49s')).not.toBeInTheDocument();
});
it('labels missing data rather than inventing a zero duration', () => {
  render(<SendingTimeCell />);
  expect(screen.getByText('Timing unavailable')).toBeInTheDocument();
  expect(screen.queryByText('0s')).not.toBeInTheDocument();
});
it('scopes the customer average to the measured recent sample', () => {
  render(<CustomerSendingSummary summary={{ sample_size: 2, recent_campaign_count: 4, average_sending_seconds: 1171.5, fastest_seconds: 1034, slowest_seconds: 1309 }} />);
  expect(screen.getByText('19m 32s')).toBeInTheDocument();
  expect(screen.getByText(/2 completed campaigns.*latest 4/)).toBeInTheDocument();
});
it('formats zero, unavailable and long durations', () => {
  expect(sendingDuration(0)).toBe('0s'); expect(sendingDuration(null)).toBe('—');
  expect(sendingDuration(NaN)).toBe('—'); expect(sendingDuration(-1)).toBe('—');
  expect(sendingDuration(90061)).toBe('1d 1h 1m 1s');
});
