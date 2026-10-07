import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { analyzeSmsMessage, composeSmsMessage, useStraightQuotes } from '@/lib/sms-message-analysis';

interface Props {
  content: string;
  includeFooter: boolean;
  footer: string;
  footerLoaded: boolean;
  onChange: (content: string) => void;
}

export function SmsMessageCounter({content, includeFooter, footer, footerLoaded, onChange}: Props) {
  const [undo, setUndo] = useState<{before:string; after:string} | null>(null);
  const finalText = composeSmsMessage(content, includeFooter, footer);
  const analysis = analyzeSmsMessage(finalText);
  const quoteReplacement = useStraightQuotes(content);
  const replacedAnalysis = analyzeSmsMessage(composeSmsMessage(quoteReplacement, includeFooter, footer));
  const footerPending = includeFooter && (!footerLoaded || !footer.trim());
  return <section className="mt-3 space-y-3 rounded-lg border bg-muted/40 p-4" aria-label="SMS length and credit estimate">
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-live="polite" aria-atomic="true">
      <div><p className="text-xs text-muted-foreground">Characters typed</p><p className="font-semibold">{Array.from(content).length}</p></div>
      <div><p className="text-xs text-muted-foreground">Characters including footer</p><p className="font-semibold">{footerPending ? 'Checking footer' : analysis.character_count}</p></div>
      <div><p className="text-xs text-muted-foreground">Estimated credits per recipient</p><p className="font-semibold">{footerPending ? 'Checking footer' : analysis.segment_count}</p></div>
    </div>
    <p className="text-sm">Message type: <strong>{analysis.encoding === 'gsm7' ? 'Standard text (GSM-7)' : 'Unicode text (UCS-2)'}</strong>.
      {' '}One credit pays for one SMS part sent to one recipient.</p>
    {analysis.encoding === 'ucs2' && <div className="rounded border border-warning/40 bg-warning/10 p-3 text-sm">
      <p>Special characters switch the whole message to Unicode: {analysis.unicodeCharacters.slice(0, 8).map(char => <code className="mr-2 inline-block break-all" key={char}>{JSON.stringify(char)} (U+{char.codePointAt(0)!.toString(16).toUpperCase().padStart(4, '0')})</code>)}{analysis.unicodeCharacters.length > 8 ? ' and more' : ''}.</p>
      <p>Unicode allows 70 text units for one SMS part, then 67 per part for longer messages. Some emoji use two units. Your message uses {analysis.unit_count} units.</p>
    </div>}
    <p className="text-xs text-muted-foreground">Standard text allows 160 units for one SMS part, then 153 per part for longer messages. Some symbols, such as € and braces, use two units. Spaces, line breaks and enabled opt-out text count too.</p>
    {quoteReplacement !== content && <div className="space-y-2">
      <p className="text-sm">Replace curly quotes in your message with straight quotes. Estimated credits per recipient afterwards: {footerPending ? 'checking footer' : replacedAnalysis.segment_count}. No other characters or saved footer settings will change.</p>
      <Button type="button" variant="outline" onClick={() => { setUndo({before:content, after:quoteReplacement}); onChange(quoteReplacement); }}>Use straight quotes</Button>
    </div>}
    {undo && content === undo.after && <Button type="button" variant="ghost" onClick={() => {onChange(undo.before);setUndo(null);}}>Undo quote change</Button>}
    <p className="text-xs text-muted-foreground">Live estimate before names and other recipient details are inserted. The next step calculates the final total for your selected recipients. Different names can change the credits required.</p>
  </section>;
}
