import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { SettingsIllustration, type SettingsArtwork } from './SettingsIllustration';
afterEach(cleanup);
it.each(['overview','profile','organization','security','compliance','integrations','help'] as SettingsArtwork[])('keeps %s artwork decorative, local and free of fabricated status text', kind => {
  const { container } = render(<SettingsIllustration kind={kind}/>);
  const art = container.querySelector('svg')!;
  expect(art).toHaveAttribute('aria-hidden', 'true'); expect(art).toHaveAttribute('focusable', 'false');
  expect(art.querySelectorAll('path,rect,circle').length).toBeGreaterThan(4);
  expect(container.querySelector('image,script,foreignObject,text')).toBeNull(); expect(screen.queryByRole('img')).not.toBeInTheDocument();
});
