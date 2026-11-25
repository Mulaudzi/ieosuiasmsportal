import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ApiDocumentation from './ApiDocumentation';
import { smsApiExamples } from '@/lib/sms-api-examples';
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ isAuthenticated:false, isLoading:false, user:null }) }));
describe('Public API documentation', () => {
  beforeEach(() => { vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Documentation must not submit requests')))); });
  const open = () => render(<MemoryRouter><ApiDocumentation/></MemoryRouter>);
  it('provides public sections, account settings and the specification without a key form', () => {
    open(); expect(screen.getByRole('main')).toBeInTheDocument();
    expect(screen.getByRole('heading',{level:1})).toHaveTextContent('Our SMS delivery');
    expect(screen.getAllByRole('link',{name:/Manage API access/})[0]).toHaveAttribute('href','/settings?tab=api');
    expect(screen.getByRole('link',{name:'Download OpenAPI'})).toHaveAttribute('href','/sms-api-v1.openapi.json');
    expect(screen.queryByLabelText(/API key/i)).not.toBeInTheDocument(); expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByRole('heading',{name:'Extra sign-in verification is optional'})).toBeInTheDocument();
    expect(screen.getByRole('link',{name:'Settings → Security'})).toHaveAttribute('href','/settings?tab=security');
  });
  it('switches all six real HTTP examples without executing them', () => {
    open(); for(const language of smsApiExamples){
      fireEvent.click(screen.getByRole('tab',{name:language.label}));
      expect(screen.getByRole('tab',{name:language.label})).toHaveAttribute('aria-selected','true');
      expect(screen.getByRole('tabpanel').querySelector('pre code')?.textContent).toBe(language.code);
    } expect(fetch).not.toHaveBeenCalled();
  });
  it('supports keyboard navigation between language tabs', () => {
    open(); const tab=screen.getByRole('tab',{name:'cURL'});tab.focus();
    fireEvent.keyDown(tab,{key:'ArrowRight'});expect(screen.getByRole('tab',{name:'Node.js'})).toHaveFocus();
    fireEvent.keyDown(screen.getByRole('tab',{name:'Node.js'}),{key:'End'});expect(screen.getByRole('tab',{name:'Java'})).toHaveAttribute('aria-selected','true');
  });
  it('documents every route, safety limit, supported status and deferred webhooks', () => {
    open(); expect(screen.getAllByText('/sms/messages/{id}/cancel').length).toBeGreaterThan(0);
    expect(screen.getByText(/Outbound webhooks to your application are/)).toHaveTextContent('not yet available');
    expect(screen.getByText(/1–1,000 phone-number strings/)).toBeInTheDocument();
    expect(screen.getByText(/A cancellation releases eligible reserved funds/)).toBeInTheDocument();
    expect(screen.getAllByText(/Idempotency-Key/).length).toBeGreaterThan(0);
    expect(screen.getByText(/"encoding": "gsm7"/)).toBeInTheDocument();
    expect(fetch).not.toHaveBeenCalled();
  });
  it('provides a safe first check and click-through plain-language glossary', () => {
    open(); expect(screen.getByRole('link',{name:/Safe connection check/})).toHaveAttribute('href','#safe-check');
    expect(screen.getByRole('heading',{name:'What does this mean?'})).toBeInTheDocument();
    for(const term of ['key','idempotency','allowance','backend','quote']) expect(document.getElementById(`term-${term}`)?.tagName).toBe('DETAILS');
    expect(screen.getByLabelText('Your server language')).toBeInTheDocument();
    expect(fetch).not.toHaveBeenCalled();
  });
  it('shows simple public results and keeps implementation details out of the public guide', () => {
    open();
    const response = screen.getByText(/"reference": "API-EXAMPLE-NOT-A-CREDENTIAL"/).textContent!;
    const result = JSON.parse(response).message;
    expect(result.status).toBe('queued'); expect(result.complete).toBe(false);
    expect(result).not.toHaveProperty('held'); expect(result).not.toHaveProperty('delivered');
    expect(screen.getByRole('table', { name: 'Public sending statuses' })).toHaveTextContent('partially_sent');
    expect(screen.getByText(/Each API send creates one campaign/)).toBeInTheDocument();
    expect(screen.getByRole('main')).not.toHaveTextContent(/LogicSMS|worker capacity|provider postback|secret_hash|FOR UPDATE|9999-12-31|failed_submissions/);
    expect(fetch).not.toHaveBeenCalled();
  });
});
