// Owner-approved destination; override VITE_FOS_APP_URL for each reviewed environment.
export function approvedFosUrl(raw: unknown): string | null {
  if (typeof raw !== "string" || !raw.trim()) return null;
  try { const url = new URL(raw); if (url.protocol !== "https:" || url.username || url.password || url.hash) return null; return url.href; } catch { return null; }
}
export function FosEcosystemCard() {
  const href = approvedFosUrl(import.meta.env.VITE_FOS_APP_URL ?? "https://fos.ieosuia.com/");
  const content = <><div aria-hidden="true" className="relative flex h-44 items-center justify-center border-b border-white/10 bg-black p-8"><span className="absolute left-4 top-3 rounded-full border border-white/30 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white">In preparation</span><svg viewBox="0 0 64 64" className="h-20 w-20" fill="white"><path d="M12 52V12h40v9H22v10h24v9H22v12Z M35 52V43h17v9Z"/></svg><span className="ml-5 text-2xl font-bold tracking-wide text-white">IEOSUIA<br/>FOS</span></div><div className="flex min-h-52 flex-1 flex-col p-5"><p className="min-h-9 text-[11px] font-bold uppercase tracking-[.18em] text-white">Funeral operations</p><h4 className="mt-4 text-xl font-bold text-white">IEOSUIA FOS</h4><p className="mt-2 text-sm leading-6 text-neutral-300">Coordinate funeral cases, teams and service planning.</p><span className="mt-auto inline-flex min-h-11 items-center justify-between rounded-lg bg-white px-4 py-3 text-sm font-bold text-black">{href ? "Explore product" : "Coming soon"}</span></div></>;
  const classes="group flex w-[84vw] max-w-sm shrink-0 flex-col snap-start overflow-hidden rounded-2xl border border-white/10 bg-black text-left text-white shadow-xl md:w-auto md:max-w-none";
  return href ? <a data-product="fos" href={href} aria-label="Explore IEOSUIA FOS" className={classes+" focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"}>{content}</a> : <article data-product="fos" aria-label="IEOSUIA FOS — coming soon" className={classes}>{content}</article>;
}
