import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ArrowDown } from 'lucide-react';

/** One action bar: fixed only while its natural position is below the viewport. */
export function SmsWizardActions({children, step, summary, showHint=true}: {children: ReactNode; step: number; summary?:ReactNode; showHint?:boolean}) {
  const anchor = useRef<HTMLDivElement>(null);
  const bar = useRef<HTMLDivElement>(null);
  const [floating, setFloating] = useState(false);
  const [bounds, setBounds] = useState({left: 0, width: 0});
  const [height,setHeight]=useState(112);
  const [bottom,setBottom]=useState(16);
  const [keyboard,setKeyboard]=useState(false);
  useEffect(() => {
    const update = () => {
      if (!anchor.current||!bar.current) return;
      const mobile = window.innerWidth < 1024;
      const viewport=window.visualViewport;
      const visibleBottom=viewport?viewport.height+viewport.offsetTop:window.innerHeight;
      const inset=Math.max(0,window.innerHeight-visibleBottom);
      const typing=inset>100;
      const clearance=mobile?(typing?8:84):16;
      setBottom(inset+clearance);setKeyboard(typing);
      const rect = anchor.current.getBoundingClientRect();
      const measured=Math.ceil(bar.current.getBoundingClientRect().height);
      setHeight(previous=>Math.max(previous,measured));
      setBounds(previous => previous.left === rect.left && previous.width === rect.width ? previous : {left:rect.left,width:rect.width});
      setFloating(rect.top+Math.max(rect.height,measured)>visibleBottom-clearance);
    };
    update();
    const observer = new ResizeObserver(update);
    if (anchor.current?.parentElement) observer.observe(anchor.current.parentElement);
    if(bar.current)observer.observe(bar.current);
    window.addEventListener('scroll', update, {passive: true,capture:true});
    window.addEventListener('resize', update);
    window.visualViewport?.addEventListener('resize',update);window.visualViewport?.addEventListener('scroll',update);
    return () => {observer.disconnect();window.removeEventListener('scroll', update,true);window.removeEventListener('resize', update);window.visualViewport?.removeEventListener('resize',update);window.visualViewport?.removeEventListener('scroll',update);};
  }, [step]);
  return <div ref={anchor} style={{minHeight:height,scrollMarginBottom:bottom+12,scrollMarginTop:80}}>
    <div ref={bar} style={floating ? {...bounds,bottom:`max(${bottom}px, env(safe-area-inset-bottom))`} : undefined} className={`${floating ? 'fixed z-30 rounded-xl border bg-card/95 p-3 shadow-xl backdrop-blur-xl' : 'border-t pt-4'} ${keyboard?'[&_[data-sms-estimate-note]]:hidden':''}`} data-keyboard={keyboard} data-wizard-actions={floating ? 'floating' : 'inline'}>
      {floating && showHint && !keyboard && <button type="button" onClick={() => anchor.current?.scrollIntoView({behavior: 'smooth', block: 'end'})} className="mb-2 flex items-center gap-1 text-xs text-muted-foreground"><ArrowDown size={14}/>Scroll down to see more before continuing</button>}
      {summary}
      <div className="flex flex-wrap items-center justify-between gap-2">{children}</div>
    </div>
  </div>;
}
