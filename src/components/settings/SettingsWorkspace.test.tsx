import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsWorkspace from './SettingsWorkspace';

const mocks = vi.hoisted(() => ({ getProfile: vi.fn(), updateProfile: vi.fn(), updateUser: vi.fn(), upload: vi.fn(), post: vi.fn(), toast: vi.fn(), user: { id: 'settings-owner', name: 'Fixture Owner', email: 'owner@example.invalid', email_verified_at: '2026-11-01', role: 'user' }, api: vi.fn(), security: vi.fn() }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: mocks.user, updateUser: mocks.updateUser, isEmailVerified: true }) }));
vi.mock('@/lib/api', () => ({ getProfile: mocks.getProfile, updateProfile: mocks.updateProfile, uploadBranding: mocks.upload, api: { post: mocks.post } }));
vi.mock('@/hooks/use-toast', () => ({ toast: mocks.toast }));
vi.mock('@/components/layout/DashboardLayout', () => ({ DashboardLayout: ({ children }: { children: React.ReactNode }) => <main>{children}</main> }));
vi.mock('./LoginSecurity', () => ({ LoginSecurity: () => { mocks.security(); return <h3>Email sign-in controls</h3>; } }));
vi.mock('./PasswordSecurityCard', () => ({ PasswordSecurityCard: () => <h3>Shared password controls</h3> }));
vi.mock('./SmsOptOutSettings', () => ({ SmsOptOutSettings: () => <label>Existing opt-out text<textarea aria-label="Existing opt-out text"/></label> }));
vi.mock('./SmsApiAccess', () => ({ SmsApiAccess: () => { mocks.api(); return <h3>Existing API management</h3>; } }));
vi.mock('./WhatsAppConnections', () => ({ WhatsAppConnections: () => <h3>Existing WhatsApp management</h3> }));
vi.mock('@/components/dashboard/GetStartedChecklist', () => ({ GetStartedChecklist: () => <section>Actual account checklist</section> }));
const initial = { success: true, user: { ...mocks.user, phone: '0820000001' }, account: { company_name: 'Fixture Company', industry: 'Retail', address: 'Fixture Street', website: 'https://example.invalid' } };
function LocationProbe() { const location = useLocation(), navigate = useNavigate(); return <><output data-testid="location">{location.pathname + location.search}</output><button onClick={() => navigate(-1)}>History back</button></>; }
function mount(url = '/settings') { const content = () => <MemoryRouter initialEntries={[url]}><LocationProbe/><Routes><Route path="/settings" element={<SettingsWorkspace/>}/><Route path="/guymhan/whatsapp" element={<h2>Admin WhatsApp</h2>}/></Routes></MemoryRouter>; const result = render(content()); return { ...result, refresh: () => result.rerender(content()) }; }
beforeEach(() => { vi.clearAllMocks(); mocks.user.role = 'user'; mocks.user.id = 'settings-owner'; mocks.user.name = 'Fixture Owner'; mocks.getProfile.mockResolvedValue(initial); mocks.updateProfile.mockResolvedValue({ success: true }); mocks.updateUser.mockResolvedValue({ success: true }); });
afterEach(cleanup);
describe('Organized Settings workspace', () => {
  it('opens a useful overview without loading security or integration controls', async () => {
    mount(); await screen.findByRole('heading', { name: 'Fixture Owner' });
    expect(screen.getByRole('heading', { name: 'Account overview' })).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Settings sections' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: /View connected apps/ })).toHaveAttribute('href', '/settings?tab=integrations');
    expect(mocks.api).not.toHaveBeenCalled(); expect(mocks.security).not.toHaveBeenCalled(); expect(mocks.post).not.toHaveBeenCalled();
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
  });
  it.each(['profile', 'organization', 'security', 'compliance', 'api', 'whatsapp', 'get-started'])('preserves existing %s deep links', async tab => {
    mount('/settings?tab=' + tab); await waitFor(() => expect(mocks.getProfile).toHaveBeenCalled());
    const titles: Record<string, string> = { profile: 'My profile', organization: 'Business details', security: 'Sign-in & security', compliance: 'SMS preferences', api: 'API connections', whatsapp: 'WhatsApp connection', 'get-started': 'Help & getting started' };
    expect(await screen.findByRole('heading', { level: 2, name: titles[tab] })).toBeInTheDocument();
    if (tab === 'api' || tab === 'whatsapp') expect(within(screen.getByRole('navigation', { name: 'Settings sections' })).getByRole('link', { name: 'Connected apps' })).toHaveAttribute('aria-current', 'page');
  });
  it('uses the mobile selector and preserves unrelated query parameters and browser history', async () => {
    mount('/settings?ref=notification'); await screen.findByRole('heading', { name: 'Fixture Owner' });
    fireEvent.change(screen.getByLabelText('Settings section'), { target: { value: 'security' } });
    expect(screen.getByTestId('location')).toHaveTextContent('/settings?ref=notification&tab=security');
    expect(screen.getByRole('heading', { level: 2, name: 'Sign-in & security' })).toHaveFocus();
    fireEvent.click(screen.getByRole('button', { name: 'History back' }));
    expect(await screen.findByRole('heading', { name: 'Account overview' })).toBeInTheDocument();
  });
  it('saves profile fields through the existing handlers and supports cancellation', async () => {
    mount('/settings?tab=profile'); fireEvent.click(await screen.findByRole('button', { name: 'Edit profile' }));
    fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Updated Owner' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' })); expect(mocks.updateProfile).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Edit profile' }));
    fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Updated Owner' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save profile' }));
    await waitFor(() => expect(mocks.updateProfile).toHaveBeenCalledWith({ name: 'Updated Owner', phone: '0820000001' }));
    expect(mocks.updateUser).toHaveBeenCalledWith({ name: 'Updated Owner', phone: '0820000001' });
    expect(await screen.findByRole('button', { name: 'Edit profile' })).toBeInTheDocument();
  });
  it('validates and saves business details without changing password or integration settings', async () => {
    mount('/settings?tab=organization'); const name = await screen.findByLabelText('Organization name');
    fireEvent.change(name, { target: { value: '' } }); fireEvent.click(screen.getByRole('button', { name: 'Save business details' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Company name is required'); expect(mocks.updateProfile).not.toHaveBeenCalled();
    fireEvent.change(name, { target: { value: 'Updated Company' } }); fireEvent.click(screen.getByRole('button', { name: 'Save business details' }));
    await waitFor(() => expect(mocks.updateProfile).toHaveBeenCalledWith({ company_name: 'Updated Company', industry: 'Retail', address: 'Fixture Street', website: 'https://example.invalid' }));
    expect(mocks.updateUser).not.toHaveBeenCalled(); expect(mocks.post).not.toHaveBeenCalled();
  });
  it('keeps unsaved business input when navigating between sections', async () => {
    mount('/settings?tab=organization'); fireEvent.change(await screen.findByLabelText('Organization name'), { target: { value: 'Not saved yet' } });
    fireEvent.change(screen.getByLabelText('Settings section'), { target: { value: 'profile' } });
    fireEvent.change(screen.getByLabelText('Settings section'), { target: { value: 'organization' } });
    expect(screen.getByLabelText('Organization name')).toHaveValue('Not saved yet'); expect(mocks.updateProfile).not.toHaveBeenCalled();
  });
  it('shows a retry instead of crashing on a failed profile read and does not block security', async () => {
    mocks.getProfile.mockRejectedValueOnce(new Error('Synthetic failure')); mount('/settings?tab=profile');
    expect(await screen.findByRole('alert')).toHaveTextContent('could not refresh');
    expect(screen.getByRole('button', { name: 'Edit profile' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Settings section'), { target: { value: 'security' } }); expect(screen.getByRole('heading', { name: 'Email sign-in controls' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry account details' })); await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  });
  it('keeps real SMS wording controls but removes unsaved compliance switches', async () => {
    mount('/settings?tab=compliance'); expect(await screen.findByLabelText('Existing opt-out text')).toBeInTheDocument();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument(); expect(screen.getByText('Wording is not a compliance switch.')).toBeInTheDocument();
  });
  it('routes admins to their existing WhatsApp administration page', async () => {
    mocks.user.role = 'admin'; mount('/settings?tab=whatsapp'); expect(await screen.findByRole('heading', { name: 'Admin WhatsApp' })).toBeInTheDocument();
  });
  it('does not submit an invalid or oversized profile photo', async () => {
    mount('/settings?tab=profile'); await screen.findByRole('button', { name: 'Edit profile' });
    fireEvent.change(screen.getByLabelText('Choose profile photo'), { target: { files: [new File(['not image'], 'note.txt', { type: 'text/plain' })] } });
    expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({ title: 'Choose a smaller image' })); expect(mocks.post).not.toHaveBeenCalled();
  });
  it('uses the existing avatar route and updates the displayed photo after success', async () => {
    mocks.post.mockResolvedValue({ success: true, avatar_url: '/synthetic-avatar.png' });
    mount('/settings?tab=profile'); await screen.findByRole('button', { name: 'Edit profile' });
    fireEvent.change(screen.getByLabelText('Choose profile photo'), { target: { files: [new File(['synthetic image'], 'photo.png', { type: 'image/png' })] } });
    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/auth/avatar', { avatar: expect.stringMatching(/^data:image\/png;base64,/) }));
    expect(await screen.findByRole('img', { name: 'Your profile photo' })).toHaveAttribute('src', '/synthetic-avatar.png');
    expect(mocks.updateUser).toHaveBeenCalledWith({ avatar_url: '/synthetic-avatar.png' });
  });
  it('clears the previous account’s form state immediately when the owner changes', async () => {
    const view = mount('/settings?tab=profile'); fireEvent.click(await screen.findByRole('button', { name: 'Edit profile' }));
    fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Private unsaved name' } });
    mocks.user.id = 'second-settings-owner'; mocks.user.name = 'Second Owner'; mocks.getProfile.mockReturnValue(new Promise(() => {}));
    view.refresh();
    expect(screen.queryByDisplayValue('Private unsaved name')).not.toBeInTheDocument();
    expect(mocks.updateProfile).not.toHaveBeenCalled(); expect(mocks.post).not.toHaveBeenCalled();
  });
});
