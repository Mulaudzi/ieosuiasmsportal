import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import CreateSmsCampaign from './CreateSmsCampaign';
import { previewSmsCampaign } from '@/lib/api';

vi.mock('@/components/layout/DashboardLayout', () => ({ DashboardLayout: ({ children }: { children: import("react").ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/use-toast', () => ({ toast: vi.fn() }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 'draft-test' } }) }));
vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(async (path: string) => path === '/settings/sms' ? { success: true, opt_out_text: 'Reply STOP to unsubscribe' } : { success: true, data: [] }) },
  getTemplates: vi.fn(async () => ({ data: [] })),
  previewSmsCampaign: vi.fn(async (input: Parameters<typeof previewSmsCampaign>[0]) => ({ preview: {
    final_content: input.content + (input.include_opt_out ? '\nReply STOP to unsubscribe' : ''),
    encoding: 'gsm7', required_credits: 1, available_credits: 10, price_per_segment: .35,
    sufficient_balance: true, recipients: ['27638082493'], sendable_count: 1,
  } })),
  createSmsCampaign: vi.fn(),
}));
afterEach(() => { cleanup(); localStorage.clear(); });

it('restores a final-review draft without trusting old totals or submitting', async () => {
  localStorage.setItem('sms-campaign-draft:v1:draft-test', JSON.stringify({version:1,step:5,name:'Saved campaign',manual:'0799282775',groupIds:['8'],contactIds:['4'],excluded:['27638082493'],selectedTemplate:'none',content:'Saved message',includeOptOut:false,schedule:'2027-01-01T12:00',submissionKey:'saved-key'}));
  render(<MemoryRouter><CreateSmsCampaign /></MemoryRouter>);
  expect(screen.getByRole('textbox', {name:'SMS message'})).toHaveValue('Saved message');
  expect(screen.getByRole('switch')).toHaveAttribute('aria-checked','false');
  expect(screen.queryByRole('button', {name:'Queue now'})).not.toBeInTheDocument();
  await waitFor(() => expect(screen.getByRole('switch')).not.toBeDisabled());
  fireEvent.click(screen.getByRole('button', {name:'Next'}));
  await screen.findByRole('button', {name:'Continue'});
  expect(previewSmsCampaign).toHaveBeenLastCalledWith(expect.objectContaining({recipients:{manual:['0799282775'],group_ids:['8'],contact_ids:['4'],excluded:['27638082493']}}));
  fireEvent.click(screen.getByRole('button', {name:'Continue'}));
  expect(document.querySelector('input[type="datetime-local"]')).toHaveValue('2027-01-01T12:00');
});

it('does not restore another account draft and safely ignores invalid storage', () => {
  localStorage.setItem('sms-campaign-draft:v1:other-user', JSON.stringify({content:'Private message'}));
  localStorage.setItem('sms-campaign-draft:v1:draft-test', '{broken');
  render(<MemoryRouter><CreateSmsCampaign /></MemoryRouter>);
  expect(screen.getByRole('textbox')).toHaveValue('');
  expect(screen.queryByText('Private message')).not.toBeInTheDocument();
});

it.each([true, false])('Step 3 retains the footer choice (%s) when previewing and returning', async (enabled) => {
  render(<MemoryRouter><CreateSmsCampaign /></MemoryRouter>);
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Footer regression' } });
  fireEvent.click(screen.getAllByRole('button', { name: 'Next' }).at(-1)!);
  fireEvent.change(screen.getByRole('textbox'), { target: { value: '27638082493' } });
  await waitFor(() => expect(screen.getAllByRole('button', { name: 'Next' }).at(-1)).not.toBeDisabled());
  fireEvent.click(screen.getAllByRole('button', { name: 'Next' }).at(-1)!);
  const toggle = await screen.findByRole('switch', { name: 'Include opt-out text' });
  await waitFor(() => expect(toggle).not.toBeDisabled());
  expect(toggle).toHaveAttribute('aria-checked', 'true');
  if (!enabled) fireEvent.click(toggle);
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Hello' } });
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await waitFor(() => expect(previewSmsCampaign).toHaveBeenCalledWith(expect.objectContaining({ content: 'Hello', include_opt_out: enabled })));
  await screen.findByRole('button', { name: 'Continue' });
  fireEvent.click(screen.getByRole('button', { name: 'Back' }));
  expect(screen.getByRole('switch', { name: 'Include opt-out text' })).toHaveAttribute('aria-checked', String(enabled));
});
