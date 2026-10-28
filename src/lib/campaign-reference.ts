/** Stable, reversible display reference. Database IDs and existing URLs stay intact.
 * Base-36 encoding is one-to-one, not a security boundary or random identifier. */
export function campaignReference(campaign: {id: string | number}) {
  const raw = String(campaign.id);
  if (!/^[1-9][0-9]*$/.test(raw)) return 'SMS-UNKNOWN';
  const encoded = BigInt(raw).toString(36).toUpperCase().padStart(6, '0');
  // Multiplication is invertible over integer IDs, avoiding sequential display numbers.
  const display = (BigInt(raw)*104729n + 7919n).toString(36).toUpperCase().padStart(7, '0');
  return `SMS-${display}-${encoded.slice(-2)}`;
}
