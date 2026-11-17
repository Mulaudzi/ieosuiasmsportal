import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { AdminFinanceWorkspace } from './AdminFinanceWorkspace';
import { AdminWorkspaceNavigation, operationSection } from './AdminWorkspaceNavigation';
import { AdminPanels, AdminPanel } from './AdminWorkspacePanels';
import { AdminSidebar, adminSidebarLinks } from '@/components/layout/AdminSidebar';
import { adminLinkActive } from '@/lib/admin-tools';
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { name: 'Admin Fixture' }, logout: vi.fn() }) }));
vi.mock('@/components/layout/Logo', () => ({ LogoSidebar: () => <span>IEOSUIA</span> }));
afterEach(cleanup);
const panels = { payments: <p>Bank-transfer review fixture</p>, cash: <input aria-label="Unsaved monetary value"/>, transfers: <p>Existing transfer verification</p>, history: <p>Existing history</p>, security: <p>Existing approval security</p> };
it('puts financial tasks in separate views without initializing all controls at once', () => {
  render(<AdminFinanceWorkspace panels={panels}/>);
  expect(screen.getByText('Bank-transfer review fixture')).toBeVisible();
  expect(screen.queryByText('Existing transfer verification')).not.toBeInTheDocument();
  expect(screen.queryByText('Existing approval security')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Credit transfers' }));
  expect(screen.getByText('Existing transfer verification')).toBeVisible();
  expect(screen.getByText('Bank-transfer review fixture')).not.toBeVisible();
});
it('preserves an in-progress balance form when changing finance sections', () => {
  render(<AdminFinanceWorkspace panels={panels}/>);
  fireEvent.click(screen.getByRole('button', { name: 'Rand balances' }));
  fireEvent.change(screen.getByLabelText('Unsaved monetary value'), { target: { value: '175.50' } });
  fireEvent.click(screen.getByRole('button', { name: 'Credit transfers' }));
  fireEvent.click(screen.getByRole('button', { name: 'Rand balances' }));
  expect(screen.getByLabelText('Unsaved monetary value')).toHaveValue('175.50');
});
it('opens payment review after selecting a history reference', () => {
  const result = render(<AdminFinanceWorkspace panels={panels}/>);
  fireEvent.click(screen.getByRole('button', { name: 'Payment history' }));
  result.rerender(<AdminFinanceWorkspace panels={panels} reviewReference={{ reference: 'synthetic' }}/>);
  expect(screen.getByText('Bank-transfer review fixture')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Payment review' })).toHaveAttribute('aria-current', 'page');
});
it('uses accessible section buttons and a mobile selector, never fake tab semantics', () => {
  const select = vi.fn(); render(<AdminWorkspaceNavigation kind="operations" value="campaigns" onChange={select}/>);
  expect(screen.getByRole('button', { name: 'Campaigns' })).toHaveAttribute('aria-current', 'page');
  fireEvent.change(screen.getByLabelText('Workspace section'), { target: { value: 'finance' } }); expect(select).toHaveBeenCalledWith('finance');
  expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
  expect(operationSection('unknown')).toBe('overview');
});
it('only mounts the chosen tool panel and preserves deep link selection', () => {
  render(<AdminPanels value="users"><AdminPanel value="users">User access fixture</AdminPanel><AdminPanel value="settings">Sensitive configuration fixture</AdminPanel></AdminPanels>);
  expect(screen.getByText('User access fixture')).toBeVisible(); expect(screen.queryByText('Sensitive configuration fixture')).not.toBeInTheDocument();
});
it.each([...adminSidebarLinks, '/guymhan/tools?tab=activity', '/guymhan/tools?tab=settings#notification-settings'])('has exactly one selected admin destination for %s', url => {
  render(<MemoryRouter initialEntries={[url]}><AdminSidebar/></MemoryRouter>);
  const nav = screen.getByRole('navigation', { name: 'Admin navigation' });
  expect(nav.querySelectorAll('a[aria-current="page"]')).toHaveLength(1);
  expect(within(nav).getByRole('heading', { name: 'Workspace' })).toBeInTheDocument();
  const location = new URL(url, 'https://example.invalid');
  expect(adminSidebarLinks.filter(href => adminLinkActive(href, location))).toHaveLength(1);
});
