// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import { exportCampaignMessages } from './api';

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); vi.useRealTimers(); localStorage.clear(); });

it('downloads the selected campaign CSV with authentication and cleans up the download link', async () => {
  vi.useFakeTimers();
  localStorage.setItem('auth_token', 'test-token');
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, blob: async () => new Blob(['State,Segments\nsent,2']) });
  vi.stubGlobal('fetch', fetchMock);
  const create = vi.fn().mockReturnValue('blob:test');
  const revoke = vi.fn();
  vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
  let filename = '';
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function(this: HTMLAnchorElement) { filename = this.download; });
  await exportCampaignMessages('11', 'sms');
  expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('/sms/campaigns-v2/11/export'), { headers: { Authorization: 'Bearer test-token' } });
  expect(filename).toBe('sms-campaign-11.csv');
  expect(click).toHaveBeenCalledTimes(1);
  expect(document.querySelector('a[download]')).toBeNull();
  vi.runAllTimers();
  expect(revoke).toHaveBeenCalledWith('blob:test');
});

it('rejects unsuccessful exports instead of downloading an error response', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }));
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  await expect(exportCampaignMessages('12')).rejects.toThrow('Export failed');
  expect(click).not.toHaveBeenCalled();
});
