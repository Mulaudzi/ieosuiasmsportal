import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Contact from './Contact';

vi.mock('@/components/layout/PublicChrome', () => ({ SignedInPublicHeader: () => null }));
vi.mock('@/components/layout/Footer', () => ({ Footer: () => null }));
vi.mock('@/components/layout/SocialLinks', () => ({ SocialLinks: () => null }));
vi.mock('@/hooks/use-toast', () => ({ toast: vi.fn() }));

afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

describe('customer contact form', () => {
  it.each(['general', 'support', 'sales'])('keeps %s inquiry submission without disclosing internal routing', async purpose => {
    const submit = vi.fn().mockResolvedValue(new Response(JSON.stringify({ success: true }), { status: 200 }));
    vi.stubGlobal('fetch', submit);
    render(<MemoryRouter initialEntries={[`/contact?purpose=${purpose}`]}><Contact /></MemoryRouter>);
    expect(screen.queryByText(/your message will be (sent|routed)|with a copy to|CC'd to/i)).not.toBeInTheDocument();
    expect(screen.queryByText('info@ieosuia.com')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Contact fixture' } });
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'fixture@example.invalid' } });
    fireEvent.change(screen.getByLabelText('Message'), { target: { value: 'A synthetic inquiry, not a real email.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send Message' }));
    await waitFor(() => expect(submit).toHaveBeenCalledTimes(1));
    const [url, request] = submit.mock.calls[0];
    expect(url).toMatch(/\/api\/contact$/);
    expect(request.method).toBe('POST');
    expect(JSON.parse(request.body)).toEqual({
      name: 'Contact fixture', email: 'fixture@example.invalid',
      message: 'A synthetic inquiry, not a real email.', purpose, originUrl: window.location.href,
    });
    await waitFor(() => expect(screen.getByLabelText('Message')).toHaveValue(''));
  });
});
