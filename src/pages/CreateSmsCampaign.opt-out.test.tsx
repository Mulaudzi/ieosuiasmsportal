vi.stubGlobal('ResizeObserver', class {observe(){} disconnect(){}});
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import CreateSmsCampaign from './CreateSmsCampaign';
import { previewSmsCampaign } from '@/lib/api';
const resources=vi.hoisted(()=>({groups:[] as Array<{id:string;name:string;contact_count:number}>}));

vi.mock('@/components/layout/DashboardLayout', () => ({ DashboardLayout: ({ children }: { children: import("react").ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/use-toast', () => ({ toast: vi.fn() }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 'draft-test' } }) }));
vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(async (path: string) => path === '/settings/sms' ? { success: true, opt_out_text: 'Reply STOP to unsubscribe' } : path==='/contact-groups'?{success:true,groups:resources.groups}:{ success: true, data: [] }) },
  getTemplates: vi.fn(async () => ({ data: [] })),
  previewSmsCampaign: vi.fn(async (input: Parameters<typeof previewSmsCampaign>[0]) => ({ preview: {
    final_content: input.content + (input.include_opt_out ? '\nReply STOP to unsubscribe' : ''),
    encoding: 'gsm7', required_credits: 1, available_credits: 10, price_per_segment: .35,
    sufficient_balance: true, recipients: ['27638082493'], sendable_count: 1,
  } })),
  createSmsCampaign: vi.fn(),
}));
afterEach(() => { cleanup(); localStorage.clear(); resources.groups=[]; });
function Composer() {return <QueryClientProvider client={new QueryClient({defaultOptions:{queries:{retry:false}}})}><CreateSmsCampaign/></QueryClientProvider>;}

it('starts group shortcuts at campaign naming and preselects only the populated group afterward',async()=>{
  resources.groups=[{id:'8',name:'Church members',contact_count:5}];
  render(<MemoryRouter initialEntries={['/sms-campaigns/new?group=8']}><Composer/></MemoryRouter>);
  expect(screen.getByLabelText('Campaign name (optional)')).toBeInTheDocument();expect(screen.queryByText('Select recipients')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Campaign name (optional)'),{target:{value:'Sunday'}});fireEvent.click(screen.getByRole('button',{name:'Next: Recipients'}));
  const group=await screen.findByRole('button',{name:/Church members.*5 contacts/});await waitFor(()=>expect(group).toHaveAttribute('aria-pressed','true'));
});

it('removes an empty Uncategorized group from restored chips as well as the send picker',async()=>{
  resources.groups=[{id:'9',name:'Uncategorized',contact_count:0}];
  localStorage.setItem('sms-campaign-draft:v1:draft-test',JSON.stringify({version:1,step:2,name:'Saved',manual:'',groupIds:['9'],contactIds:[],excluded:[],selectedTemplate:'none',content:'Hello',includeOptOut:false,schedule:'',submissionKey:'empty-group-key'}));
  render(<MemoryRouter><Composer/></MemoryRouter>);
  fireEvent.click(screen.getByRole('button',{name:'Next: Recipients'}));
  await waitFor(()=>expect(screen.queryByText('Uncategorized')).not.toBeInTheDocument());
  await waitFor(()=>expect(screen.queryByText('Unavailable group 9')).not.toBeInTheDocument());
  expect(screen.getByRole('button',{name:'Next: Message'})).toBeDisabled();
});

it('restores a final-review draft without trusting old totals or submitting', async () => {
  resources.groups=[{id:'8',name:'Saved group',contact_count:5}];
  localStorage.setItem('sms-campaign-draft:v1:draft-test', JSON.stringify({version:1,step:5,name:'Saved campaign',manual:'0799282775',groupIds:['8'],contactIds:['4'],excluded:['27638082493'],selectedTemplate:'none',content:'Saved message',includeOptOut:false,schedule:'2027-01-01T12:00',submissionKey:'saved-key'}));
  render(<MemoryRouter><Composer /></MemoryRouter>);
  expect(screen.getByLabelText('Campaign name (optional)')).toHaveValue('Saved campaign');
  expect(screen.queryByRole('textbox', {name:'SMS message'})).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Next: Recipients'}));
  await waitFor(()=>expect(screen.getByRole('button',{name:'Next: Message'})).not.toBeDisabled());
  fireEvent.click(screen.getByRole('button',{name:'Next: Message'}));
  expect(screen.getByRole('textbox', {name:'SMS message'})).toHaveValue('Saved message');
  expect(screen.getByRole('switch')).toHaveAttribute('aria-checked','false');
  expect(screen.queryByRole('button', {name:'Send SMS'})).not.toBeInTheDocument();
  await waitFor(() => expect(screen.getByRole('switch')).not.toBeDisabled());
  fireEvent.click(screen.getByRole('button', {name:'Next: Review'}));
  await screen.findByRole('button', {name:'Next: Send'});
  expect(previewSmsCampaign).toHaveBeenLastCalledWith(expect.objectContaining({recipients:{manual:['0799282775'],group_ids:['8'],contact_ids:['4'],excluded:['27638082493']}}));
  fireEvent.click(screen.getByRole('button', {name:'Next: Send'}));
  expect(document.querySelector('input[type="datetime-local"]')).toHaveValue('2027-01-01T12:00');
});

it.each([2,3,4,5])('opens restored step %s at campaign naming without losing contents',async(savedStep)=>{
  localStorage.setItem('sms-campaign-draft:v1:draft-test',JSON.stringify({version:1,step:savedStep,name:'Recovered name',manual:'0638082493',groupIds:[],contactIds:[],excluded:[],selectedTemplate:'none',content:'Recovered text',includeOptOut:false,schedule:'',submissionKey:'recovered-key'}));
  render(<MemoryRouter><Composer/></MemoryRouter>);
  expect(screen.getByLabelText('Campaign name (optional)')).toHaveValue('Recovered name');
  expect(screen.queryByText('Select recipients')).not.toBeInTheDocument();
  expect(screen.getByRole('button',{name:/Message/})).toBeDisabled();
  await waitFor(()=>expect(JSON.parse(localStorage.getItem('sms-campaign-draft:v1:draft-test')!).content).toBe('Recovered text'));
});

it('does not restore another account draft and safely ignores invalid storage', () => {
  localStorage.setItem('sms-campaign-draft:v1:other-user', JSON.stringify({content:'Private message'}));
  localStorage.setItem('sms-campaign-draft:v1:draft-test', '{broken');
  render(<MemoryRouter><Composer /></MemoryRouter>);
  expect(screen.getByRole('textbox')).toHaveValue('');
  expect(screen.queryByText('Private message')).not.toBeInTheDocument();
});

it.each([true, false])('preserves a restored explicit footer choice (%s)', async (enabled) => {
  resources.groups=[{id:'8',name:'Saved group',contact_count:5}];
  localStorage.setItem('sms-campaign-draft:v1:draft-test', JSON.stringify({version:1,step:3,name:'Saved campaign',manual:'',groupIds:['8'],contactIds:[],excluded:[],selectedTemplate:'none',content:'Saved message',includeOptOut:enabled,schedule:'',submissionKey:'saved-key'}));
  render(<MemoryRouter><Composer /></MemoryRouter>);
  fireEvent.click(screen.getByRole('button',{name:'Next: Recipients'}));
  await waitFor(()=>expect(screen.getByRole('button',{name:'Next: Message'})).not.toBeDisabled());
  fireEvent.click(screen.getByRole('button',{name:'Next: Message'}));
  const toggle=await screen.findByRole('switch',{name:'Include opt-out text'});
  await waitFor(()=>expect(toggle).not.toBeDisabled());
  expect(toggle).toHaveAttribute('aria-checked',String(enabled));
});

it.each([true, false])('Step 3 starts unchecked and retains the footer choice (%s) when previewing and returning', async (enabled) => {
  render(<MemoryRouter><Composer /></MemoryRouter>);
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Footer regression' } });
  fireEvent.click(screen.getByRole('button', { name: 'Next: Recipients' }));
  // A new account is now guided to create a group; manual entry remains optional.
  fireEvent.click(await screen.findByRole('button', { name: 'Not now' }));
  fireEvent.click(screen.getByText('Paste phone numbers'));
  fireEvent.change(screen.getByRole('textbox', {name:'Phone numbers'}), { target: { value: '27638082493' } });
  await waitFor(() => expect(screen.getByRole('button', { name: 'Next: Message' })).not.toBeDisabled());
  fireEvent.click(screen.getByRole('button', { name: 'Next: Message' }));
  const toggle = await screen.findByRole('switch', { name: 'Include opt-out text' });
  await waitFor(() => expect(toggle).not.toBeDisabled());
  expect(toggle).toHaveAttribute('aria-checked', 'false');
  if (enabled) fireEvent.click(toggle);
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Hello' } });
  fireEvent.click(screen.getByRole('button', { name: 'Next: Review' }));
  await waitFor(() => expect(previewSmsCampaign).toHaveBeenCalledWith(expect.objectContaining({ content: 'Hello', include_opt_out: enabled })));
  await screen.findByRole('button', { name: 'Next: Send' });
  fireEvent.click(screen.getByRole('button', { name: 'Back' }));
  expect(screen.getByRole('switch', { name: 'Include opt-out text' })).toHaveAttribute('aria-checked', String(enabled));
});
