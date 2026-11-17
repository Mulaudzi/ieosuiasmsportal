import {cleanup,render,screen} from '@testing-library/react';
import {afterEach,expect,it} from 'vitest';
import {DeliveryReportingHealth} from './DeliveryReportingHealth';
afterEach(cleanup);
it('does not invent data when the backend has not provided diagnostics',()=>{
  const {container}=render(<DeliveryReportingHealth report={null}/>);expect(container).toBeEmptyDOMElement();
});
it('distinguishes unavailable checks from a healthy or failed send',()=>{
  render(<DeliveryReportingHealth report={{available:false}}/>);expect(screen.getByText('Check unavailable')).toBeInTheDocument();expect(screen.getByText(/No message statuses have been changed/)).toBeInTheDocument();
});
it('shows real counts and explains missing callbacks and lookup uncertainty',()=>{
  render(<DeliveryReportingHealth report={{available:true,status:'attention',awaiting_confirmation:485,not_yet_queried:485,older_than_day:0,missing_lookups_last_day:2,last_callback_at:null,problems:['NO_RECENT_DELIVERY_CALLBACKS','MESSAGE_LOOKUPS_NEED_REVIEW']}}/>);
  expect(screen.getAllByText('485')).toHaveLength(2);expect(screen.getByText(/Verify the LogicSMS postback configuration/)).toBeInTheDocument();expect(screen.getByText(/must not trigger a resend/)).toBeInTheDocument();expect(screen.getByText(/None recorded/)).toBeInTheDocument();
});
it('shows a healthy response without warning copy',()=>{
  render(<DeliveryReportingHealth report={{available:true,status:'healthy',awaiting_confirmation:0,last_callback_at:'2026-11-05 11:00:00',problems:[]}}/>);expect(screen.getByText('Healthy')).toBeInTheDocument();expect(screen.getByText(/11:00:00 SAST/)).toBeInTheDocument();expect(screen.queryByRole('list')).not.toBeInTheDocument();
});
