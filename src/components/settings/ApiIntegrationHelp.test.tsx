import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiQuoteCheck, ApiTermHelp } from './ApiIntegrationHelp';
beforeEach(() => { vi.stubGlobal('fetch', vi.fn()); localStorage.clear(); });
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
describe('API click-through help and safe quote checker', () => {
  it('offers keyboard/click-accessible help and a glossary link', () => {
    render(<ApiTermHelp term="allowance"/>);
    expect(screen.getByText('What is daily credit allowance?')).toHaveProperty('tagName', 'SUMMARY');
    expect(screen.getByRole('link')).toHaveAttribute('href', '/developers#term-allowance');
    expect(screen.getByRole('link')).toHaveAttribute('rel', 'noopener noreferrer');
  });
  it('switches and downloads six quote-only examples without making requests', () => {
    render(<ApiQuoteCheck/>);
    for (const language of ['curl','javascript','python','php','csharp','java']) {
      fireEvent.change(screen.getByLabelText('Your server language'), { target: { value: language } });
      expect(document.querySelector('pre code')?.textContent).toContain('/sms/quotes');
      expect(document.querySelector('pre code')?.textContent).not.toContain('/sms/messages');
      expect(screen.getByRole('link', { name: /Download .* quote example/ })).toHaveAttribute('download');
    }
    expect(fetch).not.toHaveBeenCalled();
  });
  it('explains a response locally, keeps no persistent copy, and clears it', () => {
    render(<ApiQuoteCheck/>);
    const field = screen.getByLabelText('Quote response JSON — never your key');
    fireEvent.change(field, { target: { value: JSON.stringify({ success: true, quote: { binding: false, sendable: 0, credits_required: 0, sufficient_balance: false } }) } });
    fireEvent.click(screen.getByRole('button', { name: 'Explain quote response' }));
    expect(screen.getByRole('status')).toHaveTextContent('No recipients are sendable');
    expect(screen.getByRole('status')).toHaveTextContent('not independent connection or delivery verification');
    expect(fetch).not.toHaveBeenCalled(); expect(localStorage.length).toBe(0);
    fireEvent.click(screen.getByRole('button', { name: 'Clear response' })); expect(field).toHaveValue('');
  });
  it('clears an accidentally pasted credential without sending or storing it', () => {
    render(<ApiQuoteCheck/>); const field = screen.getByLabelText('Quote response JSON — never your key');
    fireEvent.change(field, { target: { value: 'smsk_' + 'a'.repeat(32) + '.' + 'b'.repeat(64) } });
    fireEvent.click(screen.getByRole('button', { name: 'Explain quote response' }));
    expect(field).toHaveValue(''); expect(screen.getByRole('status')).toHaveTextContent('Do not paste API keys');
    expect(fetch).not.toHaveBeenCalled(); expect(localStorage.length).toBe(0);
  });
});
