import { ReactNode, useEffect, useRef, useState } from 'react';
import { ArrowDown } from 'lucide-react';

/** Keep financial actions visible and explain when verification fields are below the fold. */
export function CreditTransferDialogBody({children}:{children:ReactNode}) {
  const scroll=useRef<HTMLDivElement>(null),content=useRef<HTMLDivElement>(null);
  const [overflow,setOverflow]=useState(false),[atEnd,setAtEnd]=useState(false);
  const inspect=()=>{const el=scroll.current;if(el){setOverflow(el.scrollHeight>el.clientHeight+4);setAtEnd(el.scrollTop+el.clientHeight>=el.scrollHeight-8);}};
  useEffect(()=>{
    const frame=requestAnimationFrame(()=>{const el=scroll.current;if(el){el.focus({preventScroll:true});el.scrollTop=0;}inspect();});
    const observer=typeof ResizeObserver==='undefined'?null:new ResizeObserver(inspect);
    if(scroll.current)observer?.observe(scroll.current);if(content.current)observer?.observe(content.current);
    window.addEventListener('resize',inspect);
    return()=>{cancelAnimationFrame(frame);observer?.disconnect();window.removeEventListener('resize',inspect);};
  },[]);
  return <div className="flex min-h-0 flex-1 flex-col gap-2">
    {overflow&&<p role="status" className="flex shrink-0 items-start gap-2 rounded-lg bg-primary/5 px-3 py-2 text-xs text-muted-foreground"><ArrowDown className="h-4 w-4 shrink-0"/>{atEnd?'You have reached the end of this review.':'Scroll down to see all details and verification fields.'}</p>}
    <div ref={scroll} tabIndex={-1} onScroll={inspect} className="min-h-0 flex-1 overflow-y-auto p-1"><div ref={content} className="space-y-4">{children}</div></div>
  </div>;
}
