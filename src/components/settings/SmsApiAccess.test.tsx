import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SmsApiAccess } from './SmsApiAccess';
import { createSmsIntegration, getSmsIntegrations, revokeSmsIntegration } from '@/lib/sms-integrations';

const identity = vi.hoisted(() => ({ id: '1' }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: identity }) }));
vi.mock('@/lib/sms-integrations', async original => ({ ...await original<typeof import('@/lib/sms-integrations')>(), getSmsIntegrations: vi.fn(), createSmsIntegration: vi.fn(), issueSmsIntegrationKey: vi.fn(), revokeSmsIntegration: vi.fn() }));
const fixture = { id: 'connection-fixture', name: 'Events production', product: 'events', status: 'active' as const, scopes: ['sms:send'], daily_credit_limit: 1000, requests_per_minute: 60, admitted_credits_today: 17, keys: [{ id: 'key-fixture-123', created_at: 1, expires_at: 4102444800, revoked_at: null, last_used_at: null }] };
const secret = 'synthetic-one-time-credential';
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrap = () => <QueryClientProvider client={client}><MemoryRouter><SmsApiAccess /></MemoryRouter></QueryClientProvider>;
  return { client, ...render(wrap()), wrap };
}
beforeEach(() => { vi.clearAllMocks(); identity.id = '1'; localStorage.clear(); vi.mocked(getSmsIntegrations).mockResolvedValue({ available: true, can_issue_keys: true, connections: [] }); vi.mocked(createSmsIntegration).mockResolvedValue({ connection_id: 'new-fixture', credential: { id: 'new-key', token: secret, expires_at: 4102444800, shown_once: true } }); });
afterEach(cleanup);

describe('SMS API access settings', () => {
  it('stays unavailable while the schema and feature are not activated', async () => {
    vi.mocked(getSmsIntegrations).mockResolvedValue({ available: false, connections: [] }); mount();
    expect(await screen.findByText(/awaiting its database setup and activation/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add connection' })).toBeDisabled();
    expect(createSmsIntegration).not.toHaveBeenCalled();
  });
  it('requires step-up for issuance but still allows explicit revocation', async () => {
    vi.mocked(getSmsIntegrations).mockResolvedValue({ available: true, can_issue_keys: false, connections: [fixture] }); mount();
    expect(await screen.findByRole('link', { name: /enable the extra SMS Portal sign-in check/ }, { timeout: 10000 })).toHaveAttribute('href', '/settings?tab=security');
    expect(screen.getByRole('button', { name: 'Add connection' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Revoke connection' })).not.toBeDisabled();
  });
  it('shows the secret once without placing it in persistent storage or the query cache', async () => {
    const { client } = mount();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Add connection' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Add connection' }));
    fireEvent.change(screen.getByLabelText('Connection name'), { target: { value: 'Events test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create connection and key' }));
    expect(await screen.findByLabelText('New API key')).toHaveValue(secret);
    expect(createSmsIntegration).toHaveBeenCalledWith(expect.objectContaining({ name: 'Events test', daily_credit_limit: 1000, requests_per_minute: 60, scopes: ['sms:quote', 'sms:send', 'sms:read'] }));
    expect(JSON.stringify(client.getQueryData(['sms-api-access', '1']))).not.toContain(secret);
    expect(localStorage.getItem('sms_api_key')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'I have saved the key' }));
    expect(screen.queryByLabelText('New API key')).not.toBeInTheDocument();
  });
  it('does not expose the previous account’s one-time credential after an account switch', async () => {
    const view = mount(); await waitFor(() => expect(screen.getByRole('button', { name: 'Add connection' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Add connection' })); fireEvent.change(screen.getByLabelText('Connection name'), { target: { value: 'Fixture' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create connection and key' })); await screen.findByLabelText('New API key');
    identity.id = '2'; view.rerender(view.wrap());
    expect(screen.queryByLabelText('New API key')).not.toBeInTheDocument();
  });
  it('requires confirmation before revoking an explicitly selected connection', async () => {
    vi.mocked(getSmsIntegrations).mockResolvedValue({ available: true, can_issue_keys: true, connections: [fixture] });
    vi.mocked(revokeSmsIntegration).mockResolvedValue({ success: true }); mount();
    await screen.findByText('Events production');
    fireEvent.click(screen.getByRole('button', { name: 'Revoke connection' }));
    expect(revokeSmsIntegration).not.toHaveBeenCalled();
    expect(screen.getByText(/Already queued messages are not cancelled/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Revoke access' }));
    await waitFor(() => expect(revokeSmsIntegration).toHaveBeenCalledWith(fixture.id, undefined));
  });
  it('does not create a connection with an invalid allowance', async () => {
    mount(); await waitFor(() => expect(screen.getByRole('button', { name: 'Add connection' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Add connection' }));
    fireEvent.change(screen.getByLabelText('Connection name'), { target: { value: 'Fixture' } });
    fireEvent.change(screen.getByLabelText('Daily credit allowance'), { target: { value: '-1' } });
    expect(screen.getByRole('button', { name: 'Create connection and key' })).toBeDisabled();
  });
  it('links to the full guide, explains connection fields and never sends an onboarding SMS', async () => {
    mount(); await waitFor(() => expect(screen.getByRole('button', { name: 'Add connection' })).toBeEnabled());
    fireEvent.click(screen.getByText('Need help connecting an app?'));
    expect(screen.getByRole('link', { name: 'Full integration guide ↗' })).toHaveAttribute('href', '/developers#start');
    fireEvent.click(screen.getByRole('button', { name: 'Add connection' }));
    for (const term of ['product label','daily credit allowance','permissions']) expect(screen.getByText(`What is ${term}?`)).toHaveProperty('tagName', 'SUMMARY');
    expect(screen.getByRole('option', { name: 'Other / custom software' })).toHaveAttribute('value', 'custom');
    expect(createSmsIntegration).not.toHaveBeenCalled();
  });
  it('distinguishes user-confirmed storage from actual recorded key activity and resets on account switch', async () => {
    vi.mocked(getSmsIntegrations).mockResolvedValue({ available: true, can_issue_keys: true, connections: [fixture] });
    const view = mount(); await screen.findByText('Events production');
    fireEvent.click(screen.getByText('Need help connecting an app?'));
    expect(screen.getByText(/2 of 4 setup checks complete/)).toBeInTheDocument();
    const stored = screen.getByRole('checkbox', { name: /I have stored this connection/ });
    fireEvent.click(stored); expect(screen.getByText(/3 of 4 setup checks complete/)).toBeInTheDocument();
    expect(screen.getByText(/Confirmed by you for this visit/)).toBeInTheDocument();
    expect(screen.getByText(/No authenticated use is recorded/)).toBeInTheDocument();
    identity.id = '2'; view.rerender(view.wrap());
    expect(screen.getByRole('checkbox', { name: /I have stored this connection/ })).not.toBeChecked();
  });
  it('keeps setup guidance and key details out of the way until requested', async () => {
    vi.mocked(getSmsIntegrations).mockResolvedValue({ available: true, can_issue_keys: true, connections: [fixture] });
    mount(); await screen.findByText('Events production');
    expect(screen.getByText('Need help connecting an app?').closest('details')).not.toHaveAttribute('open');
    expect(screen.getByText('Keys & permissions (1)').closest('details')).not.toHaveAttribute('open');
    expect(screen.getByRole('link', { name: 'Developer guide' })).toHaveAttribute('href', '/developers');
    fireEvent.click(screen.getByText('Keys & permissions (1)'));
    expect(screen.getByRole('button', { name: 'Revoke key key-fixt' })).toBeInTheDocument();
    expect(createSmsIntegration).not.toHaveBeenCalled(); expect(revokeSmsIntegration).not.toHaveBeenCalled();
  });
});
