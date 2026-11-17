import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { NotificationBell } from './NotificationBell';
import { ApiError } from '@/lib/api-error';

const mocks=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),delete:vi.fn()}));
vi.mock('@/lib/api',()=>({api:mocks}));
vi.mock('@/hooks/useAuth',()=>({useAuth:()=>({user:{id:'notification-fixture'}})}));
beforeEach(()=>{
  vi.spyOn(document,'hidden','get').mockReturnValue(false);
  vi.stubGlobal('ResizeObserver',class {observe(){} unobserve(){} disconnect(){}});
  vi.stubGlobal('IntersectionObserver',class {observe(){} unobserve(){} disconnect(){}});
});
afterEach(()=>{cleanup();vi.restoreAllMocks();vi.clearAllMocks();vi.unstubAllGlobals();});

function Location(){const location=useLocation();return <p data-testid="notification-location">{location.pathname+location.search}</p>;}
it.each([['credits_received','/wallet'],['credits_transferred','/wallet'],['account_security','/settings?tab=security'],['delivery_updated','/sms-campaigns/24']])('opens the appropriate page for %s',async(type,target)=>{
 mocks.get.mockResolvedValue({success:true,notifications:[{id:'17',type,title:'Fixture alert',message:'Safe activity update',read:true,created_at:'2026-10-30',data:type==='delivery_updated'?{campaign_id:24}:{}}],unread_count:0});
 render(<MemoryRouter><NotificationBell/><Location/></MemoryRouter>);fireEvent.click(screen.getByRole('button',{name:'Notifications, 0 unread'}));fireEvent.click(await screen.findByText('Fixture alert'));
 expect(screen.getByTestId('notification-location')).toHaveTextContent(target);
});

it('pauses polling and focus/reconnect refreshes after a hosting block until explicit retry',async()=>{
  let tick:()=>void=()=>{};
  const originalInterval=globalThis.setInterval;
  vi.spyOn(globalThis,'setInterval').mockImplementation(((handler:()=>void,delay:number)=>{if(delay===30000){tick=handler;return 123;}return originalInterval(handler,delay);}) as typeof setInterval);
  mocks.get.mockRejectedValueOnce(new ApiError('Hosting security blocked this request. Automatic retries are paused.',403,'hosting-blocked'))
    .mockResolvedValue({success:true,notifications:[],unread_count:0});
  render(<MemoryRouter><NotificationBell/></MemoryRouter>);
  await waitFor(()=>expect(mocks.get).toHaveBeenCalledTimes(1));
  await act(async()=>{});
  fireEvent(window,new Event('focus'));fireEvent(window,new Event('online'));
  await act(async()=>{tick();tick();});
  expect(mocks.get).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button',{name:'Notifications, 0 unread'}));
  expect(await screen.findByRole('alert')).toHaveTextContent('Hosting security');
  expect(mocks.get).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button',{name:'Retry'}));
  await waitFor(()=>expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  expect(mocks.get).toHaveBeenCalledTimes(2);
  await act(async()=>{tick();});
  expect(mocks.get).toHaveBeenCalledTimes(3);
});
