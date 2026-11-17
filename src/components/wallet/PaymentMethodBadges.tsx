import { Landmark } from 'lucide-react';

/** Local vector marks keep payment choices identifiable without external image requests. */
export function EftPaymentBadge({ instant = false }: { instant?: boolean }) {
  return <span className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2 py-1 text-xs font-semibold text-foreground">
    <Landmark className="h-4 w-4 text-primary" aria-hidden="true" />
    {instant ? 'Instant EFT' : 'EFT'}
  </span>;
}

export function MastercardPaymentBadge() {
  return <span className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2 py-1 text-xs font-semibold text-foreground">
    <svg viewBox="0 0 38 24" className="h-5 w-8 shrink-0" aria-hidden="true" focusable="false">
      <circle cx="13" cy="12" r="11" fill="#EB001B" />
      <circle cx="25" cy="12" r="11" fill="#F79E1B" />
      <path d="M19 2.78a10.98 10.98 0 0 1 0 18.44 10.98 10.98 0 0 1 0-18.44Z" fill="#FF5F00" />
    </svg>
    Mastercard
  </span>;
}
