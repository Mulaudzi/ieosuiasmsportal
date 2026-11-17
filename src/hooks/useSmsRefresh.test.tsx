import {act,cleanup,render} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {SMS_REFRESH_MS,useSmsRefresh} from './useSmsRefresh';
beforeEach(()=>vi.useFakeTimers());
afterEach(()=>{cleanup();vi.useRealTimers();});
function Refresh({run}:{run:()=>Promise<unknown>|void}){useSmsRefresh(run);return null;}
it('refreshes internal reads within 15 seconds and immediately on focus',async()=>{
  const run=vi.fn();render(<Refresh run={run}/>);
  await act(async()=>vi.advanceTimersByTimeAsync(SMS_REFRESH_MS));expect(run).toHaveBeenCalledTimes(1);
  await act(async()=>window.dispatchEvent(new Event('focus')));expect(run).toHaveBeenCalledTimes(2);
});
it('does not overlap refreshes and stops when unmounted',async()=>{
  const run=vi.fn(()=>new Promise(()=>{}));const view=render(<Refresh run={run}/>);
  await act(async()=>vi.advanceTimersByTimeAsync(SMS_REFRESH_MS*3));expect(run).toHaveBeenCalledTimes(1);
  view.unmount();await act(async()=>vi.advanceTimersByTimeAsync(SMS_REFRESH_MS*3));expect(run).toHaveBeenCalledTimes(1);
});
it('uses the current account or filter callback instead of a stale closure',async()=>{
  const old=vi.fn(),latest=vi.fn();const view=render(<Refresh run={old}/>);view.rerender(<Refresh run={latest}/>);
  await act(async()=>window.dispatchEvent(new Event('focus')));expect(old).not.toHaveBeenCalled();expect(latest).toHaveBeenCalledTimes(1);
});
