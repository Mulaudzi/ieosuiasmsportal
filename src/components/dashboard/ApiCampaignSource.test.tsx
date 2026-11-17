import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ApiCampaignSource } from './ApiCampaignSource';

const source = { type: 'api' as const, connection_name: 'Blu-T production', reference: 'API-EXAMPLE', external_reference: 'blu-t:order:123' };
describe('API campaign attribution', () => {
  it('leaves manual campaigns unchanged', () => {
    const { container } = render(<ApiCampaignSource/>); expect(container).toBeEmptyDOMElement();
  });
  it('keeps list attribution compact and details discoverable inside the campaign', () => {
    const { rerender } = render(<ApiCampaignSource source={source}/>);
    expect(screen.getByLabelText('API campaign source')).toHaveTextContent('Blu-T production');
    expect(screen.queryByText(/API-EXAMPLE/)).not.toBeInTheDocument();
    rerender(<ApiCampaignSource source={source} details/>);
    expect(screen.getByText('API reference: API-EXAMPLE')).toBeInTheDocument();
    expect(screen.getByText('Application reference: blu-t:order:123')).toBeInTheDocument();
  });
  it('renders application-supplied labels as text, never HTML', () => {
    const { container } = render(<ApiCampaignSource source={{ ...source, connection_name: '<img src=x onerror=alert(1)>', external_reference: '<script>unsafe</script>' }} details/>);
    expect(container.querySelector('img, script')).toBeNull();
    expect(container).toHaveTextContent('<script>unsafe</script>');
  });
});
