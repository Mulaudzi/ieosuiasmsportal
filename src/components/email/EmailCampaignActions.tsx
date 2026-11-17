import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ArrowDown } from 'lucide-react';

/** Do not rely on sticky: the existing mobile shell has overflow-constrained ancestors. */
export function EmailCampaignActions({children}: {children: ReactNode}) {
  const anchor=useRef<HTMLDivElement>(null),bar=useRef<HTMLDivElement>(null);
  const [floating,setFloating]=useState(false),[height,setHeight]=useState(96);
  const [bounds,setBounds]=useState({left:0,width:0});
  useEffect(()=>{
    const update=()=>{
      if(!anchor.current||!bar.current)return;
      const rect=anchor.current.getBoundingClientRect(),parent=anchor.current.parentElement?.getBoundingClientRect();
      const measured=Math.ceil(bar.current.getBoundingClientRect().height);
      setHeight(previous=>Math.max(previous,measured));
      setBounds(previous=>previous.left===rect.left&&previous.width===rect.width?previous:{left:rect.left,width:rect.width});
      const bottom=window.innerHeight-(window.innerWidth<1024?88:24);
      setFloating(!!parent&&parent.top<bottom&&parent.bottom>80&&rect.top+Math.max(rect.height,measured)>bottom);
    };
    update();const observer=new ResizeObserver(update);
    if(anchor.current?.parentElement)observer.observe(anchor.current.parentElement);
    if(bar.current)observer.observe(bar.current);
    window.addEventListener('scroll',update,{passive:true});window.addEventListener('resize',update);
    return()=>{observer.disconnect();window.removeEventListener('scroll',update);window.removeEventListener('resize',update);};
  },[]);
  return <div ref={anchor} style={{minHeight:height}} className="mt-5">
    <div ref={bar} data-email-actions={floating?'floating':'inline'} style={floating?bounds:undefined} className={floating?'fixed bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 rounded-xl border bg-card/95 p-3 shadow-xl backdrop-blur lg:bottom-4':'border-t bg-card py-4'}>
      {floating&&<button type="button" className="mb-2 flex items-center gap-1 text-xs text-muted-foreground" onClick={()=>anchor.current?.scrollIntoView({behavior:'smooth',block:'end'})}><ArrowDown className="h-3 w-3"/>Scroll down to see more</button>}
      <div className="flex flex-wrap justify-end gap-3">{children}</div>
    </div>
  </div>;
}
