import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BankingDetails from './BankingDetails';

vi.mock('@/components/layout/PublicChrome', () => ({ SignedInPublicHeader: () => null }));
vi.mock('@/components/layout/Footer', () => ({ Footer: () => null }));
const bank = { bank_name: 'Fixture Bank', account_name: 'Fixture Account', account_number: '123456789', account_type: 'Business', branch_code: '123456' };
const fetcher = vi.fn();
const reply = (data: object) => ({ ok: true, headers: new Headers({ 'content-type': 'application/json' }), json: async () => ({ success: true, data }) });
beforeEach(() => { vi.stubGlobal('fetch', fetcher); fetcher.mockReset(); fetcher.mockResolvedValue(reply({ available: true, bank_details: bank })); });
afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals(); delete (window as Window & { __IEOSUIA_PUBLIC_PRERENDER__?: boolean }).__IEOSUIA_PUBLIC_PRERENDER__; });
const show = () => render(<MemoryRouter><BankingDetails/></MemoryRouter>);
it('loads banking details without login or auth headers while retaining same-site hosting verification', async () => {
 show(); expect(await screen.findByText(bank.account_number)).toBeVisible();
 expect(fetcher).toHaveBeenCalledWith('/api/public/banking-details', expect.objectContaining({ cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } }));
 expect(screen.getByText(/Use the unique SMS purchase reference starting with SMS-/)).toBeVisible();
 expect(screen.getByText('registered SMS Portal email address')).toBeVisible();
 expect(screen.getAllByRole('link', { name: 'Create a credit purchase' })[0]).toHaveAttribute('href', '/wallet#buy-credits');
 expect(screen.getAllByRole('link', { name: 'sms@ieosuia.com' }).some(link => link.getAttribute('href') === 'mailto:sms@ieosuia.com')).toBe(true);
 expect(screen.getByText(/Credits are added after cleared funds have been verified/)).toBeVisible();
});
it('copies the exact account number', async () => {
 const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined);
 show(); fireEvent.click(await screen.findByRole('button', { name: 'Copy Account number' }));
 await waitFor(() => expect(writeText).toHaveBeenCalledWith(bank.account_number));
 expect(await screen.findByText('Account number copied')).toBeVisible();
});
it('does not reveal cached bank numbers when transfers are disabled', async () => {
 fetcher.mockResolvedValue(reply({ available: false, bank_details: null })); show();
 expect(await screen.findByText(/Direct EFT instructions are currently unavailable/)).toBeVisible();
 expect(screen.queryByText(bank.account_number)).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Copy Account number' })).not.toBeInTheDocument();
});
it('fails closed on HTML and supports retry without exposing stale details', async () => {
 fetcher.mockResolvedValueOnce({ ok: true, headers: new Headers({ 'content-type': 'text/html' }) }); show();
 expect(await screen.findByRole('alert')).toHaveTextContent('could not load the current banking details');
 expect(screen.queryByText(bank.account_number)).not.toBeInTheDocument(); fireEvent.click(screen.getByRole('button', { name: 'Retry banking details' }));
 expect(await screen.findByText(bank.account_number)).toBeVisible();
});
it('rejects incomplete banking responses', async () => {
 fetcher.mockResolvedValue(reply({ available: true, bank_details: { account_number: bank.account_number } })); show();
 expect(await screen.findByRole('alert')).toBeVisible(); expect(screen.queryByText(bank.account_number)).not.toBeInTheDocument();
});
it('times out instead of showing an endless loader', async () => {
 vi.useFakeTimers(); fetcher.mockReturnValue(new Promise(() => {})); show();
 await act(async () => { vi.advanceTimersByTime(15001); }); expect(screen.getByRole('alert')).toBeVisible();
});
it('prerenders instructions only without API requests or frozen account numbers', () => {
 (window as Window & { __IEOSUIA_PUBLIC_PRERENDER__?: boolean }).__IEOSUIA_PUBLIC_PRERENDER__ = true; show();
 expect(fetcher).not.toHaveBeenCalled(); expect(screen.queryByText(bank.account_number)).not.toBeInTheDocument();
 expect(screen.getByText(/current banking details securely/)).toBeVisible();
});
