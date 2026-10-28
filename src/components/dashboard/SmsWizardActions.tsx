import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ArrowDown } from 'lucide-react';

/** One action bar: fixed only while its natural position is below the viewport. */
export function SmsWizardActions({children, step}: {children: ReactNode; step: number}) {
  const anchor = useRef<HTMLDivElement>(null);
  const [floating, setFloating] = useState(false);
  const [bounds, setBounds] = useState({left: 0, width: 0});
  useEffect(() => {
    const update = () => {
      if (!anchor.current) return;
      const mobile = window.innerWidth < 1024 ? 80 : 0;
      const rect = anchor.current.getBoundingClientRect();
      setBounds(previous => previous.left === rect.left && previous.width === rect.width ? previous : {left:rect.left,width:rect.width});
      setFloating(rect.top > window.innerHeight - mobile - 90);
    };
    update();
    const observer = new ResizeObserver(update);
    if (anchor.current?.parentElement) observer.observe(anchor.current.parentElement);
    window.addEventListener('scroll', update, {passive: true});
    window.addEventListener('resize', update);
    return () => {observer.disconnect();window.removeEventListener('scroll', update);window.removeEventListener('resize', update);};
  }, [step]);
  return <div ref={anchor} className="h-28 sm:h-24">
    <div style={floating ? bounds : undefined} className={floating ? 'fixed bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 rounded-xl border bg-card/95 p-3 shadow-xl backdrop-blur-xl lg:bottom-4' : 'border-t pt-4'} data-wizard-actions={floating ? 'floating' : 'inline'}>
      {floating && <button type="button" onClick={() => anchor.current?.scrollIntoView({behavior: 'smooth', block: 'end'})} className="mb-2 flex items-center gap-1 text-xs text-muted-foreground"><ArrowDown size={14}/>Scroll down to see more before continuing</button>}
      <div className="flex items-center justify-between gap-2">{children}</div>
    </div>
  </div>;
}
