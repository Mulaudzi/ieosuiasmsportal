import {cleanup,render,screen} from '@testing-library/react';
import {afterEach,expect,it} from 'vitest';
import {SendingOverview} from './SendingOverview';
import {CampaignSendingProgress} from './CampaignSendingProgress';
import type {SummaryCampaign} from './CampaignSummary';
afterEach(cleanup);
it('does not describe unconfirmed submissions as active sends or delivery failures',()=>{
  render(<SendingOverview total={5} sent={4} held={1}/>);
  expect(screen.getByText('Awaiting provider confirmation')).toBeInTheDocument();
  expect(screen.getByText(/may already have arrived/)).toBeInTheDocument();
  expect(screen.queryByText(/waiting to send/)).not.toBeInTheDocument();
});
it('reassures after provider acceptance without asserting delivery',()=>{
  render(<SendingOverview total={3} sent={3} campaign/>);
  expect(screen.getByText('Your SMS campaign has been sent successfully!')).toBeInTheDocument();
  expect(screen.getByText(/Delivery confirmations will update automatically/)).toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','100');
});
it('does not claim queued messages were sent',()=>{
  render(<SendingOverview total={3} sent={0}/>);
  expect(screen.getByText('Getting your messages ready')).toBeInTheDocument();
  expect(screen.getByRole('status')).toHaveTextContent('Waiting for the first sending result');
  expect(screen.queryByText('Your SMS campaign has been sent successfully!')).not.toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','0');
  expect(screen.getByRole('progressbar').parentElement?.querySelector('.sending-progress-track__fill')).toHaveStyle({width:'0%'});
});
it('separates completed processing from failed submissions',()=>{
  render(<SendingOverview total={3} sent={1} failed={1} excluded={1}/>);
  expect(screen.getByText('1 message successfully sent')).toBeInTheDocument();
  expect(screen.getByText(/1 submission was unsuccessful/)).toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','100');
});
it('updates the actual progress and stage without creating a countdown',()=>{
  const {rerender}=render(<SendingOverview total={100} sent={40} state="processing"/>);
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','40');
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','sending');
  expect(screen.getByText('Sending',{exact:true}).closest('li')).toHaveAttribute('aria-current','step');
  rerender(<SendingOverview total={100} sent={75} state="processing"/>);
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','75');
  expect(screen.getByRole('progressbar').parentElement?.querySelector('.sending-progress-track__fill')).toHaveStyle({width:'75%'});
  expect(screen.queryByText(/seconds left|minutes left|delivered successfully/i)).not.toBeInTheDocument();
});
it.each(['scheduled','cancelled','draft'])('does not animate %s as active sending',state=>{
  render(<SendingOverview total={100} sent={0} state={state}/>);
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','still');
  expect(screen.queryByText('Sending in progress')).not.toBeInTheDocument();
});
it.each(['queued','pending','processing'])('animates %s preparation without inventing a successful send',state=>{
  const {rerender}=render(<SendingOverview total={100} sent={0} state={state}/>);
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','preparing');
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','0');
  expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuetext',expect.stringContaining('Waiting for the first sending result'));
  expect(screen.getByRole('status')).toHaveTextContent('Waiting for the first sending result');
  rerender(<SendingOverview total={100} sent={20} state="processing"/>);
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','sending');
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','20');
  expect(screen.getByRole('status')).toHaveTextContent('Your campaign is on the move');
  rerender(<SendingOverview total={100} sent={100} state="completed"/>);
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','still');
  expect(screen.queryByText('Your campaign is on the move')).not.toBeInTheDocument();
});
it('does not animate unconfirmed-only results as preparation',()=>{
  render(<SendingOverview total={4} sent={0} held={4} state="processing"/>);
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','still');
  expect(screen.queryByText('Waiting for the first sending result')).not.toBeInTheDocument();
});
it('does not animate a held-only campaign or claim it is still sending',()=>{
  render(<SendingOverview total={5} sent={4} held={1} state="processing" compact/>);
  expect(screen.getByRole('region')).toHaveAttribute('data-motion','still');
  expect(screen.getByText('Awaiting provider confirmation')).toBeInTheDocument();
  expect(screen.getByRole('status')).toHaveTextContent('original credits remain reserved');
});
const fixture=(changes:Partial<SummaryCampaign>={}):SummaryCampaign=>({id:'fixture',name:'Sunday service',state:'processing',recipient_count:100,sent_count:40,delivered_count:0,failed_count:0,failed_submission_count:0,created_at:'2026-10-30',...changes});
it('shows compact progress from actual sending counts, not network failures',()=>{
  render(<CampaignSendingProgress campaign={fixture({failed_count:15})}/>);
  expect(screen.getByRole('progressbar',{name:'Sending progress for Sunday service'})).toHaveAttribute('value','40');
  expect(screen.getByText('40 of 100 confirmed')).toBeInTheDocument();
});
it('keeps completed campaign rows uncluttered',()=>{
  const {container}=render(<CampaignSendingProgress campaign={fixture({state:'completed',sent_count:100})}/>);
  expect(container).toBeEmptyDOMElement();
});
it('shows confirmation rather than sending for held rows',()=>{
  render(<CampaignSendingProgress campaign={fixture({sent_count:99,held_submission_count:1})}/>);
  expect(screen.getByText('1 awaiting confirmation')).toBeInTheDocument();
});
it('shows animated preparation in the campaign list without increasing its counts',()=>{
  const {rerender}=render(<CampaignSendingProgress campaign={fixture({state:'queued',sent_count:0})}/>);
  expect(screen.getByText('Queued · getting ready')).toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','0');
  expect(screen.getByRole('progressbar').closest('.campaign-sending-mini')).toHaveAttribute('data-motion','preparing');
  rerender(<CampaignSendingProgress campaign={fixture({state:'processing',sent_count:25})}/>);
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','25');
  expect(screen.getByRole('progressbar').closest('.campaign-sending-mini')).toHaveAttribute('data-motion','sending');
});
