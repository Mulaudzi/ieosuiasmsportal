import {cleanup,render,screen} from '@testing-library/react';
import {afterEach,expect,it} from 'vitest';
import {SendingOverview} from './SendingOverview';
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
  expect(screen.getByText('Sending in progress')).toBeInTheDocument();
  expect(screen.queryByText('Your SMS campaign has been sent successfully!')).not.toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','0');
});
it('separates completed processing from failed submissions',()=>{
  render(<SendingOverview total={3} sent={1} failed={1} excluded={1}/>);
  expect(screen.getByText('1 message successfully sent')).toBeInTheDocument();
  expect(screen.getByText(/1 submission was unsuccessful/)).toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','100');
});
