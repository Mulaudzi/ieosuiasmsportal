import { deliveryOutcomes } from '@/lib/delivery-presentation';
import { MetricHint } from './MetricHint';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/** Counts are supplied by the API; this component never changes their meaning. */
export function DeliveryOutcomeList({counts, tooltips = true, countFirst = false, divided = false, total, compact=false}: {counts:number[];tooltips?:boolean;countFirst?:boolean;divided?:boolean;total?:number;compact?:boolean}) {
  return <div className={divided?'divide-y':compact?'space-y-0.5':'space-y-2'}>{deliveryOutcomes.map((outcome,index)=>{
    const row=<div className={`flex items-center gap-2 ${compact?'text-xs':'text-sm'} ${divided?'py-3':compact?'py-0.5':'py-1'}`} tabIndex={tooltips?0:undefined}>
      <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-full" style={{background:`var(--delivery-${outcome.key})`}}/>
      {countFirst&&<strong className="shrink-0 tabular-nums" style={{color:`var(--delivery-${outcome.key})`}}>{(counts[index]??0).toLocaleString()}</strong>}
      <span className="min-w-0 flex-1">{outcome.label}{!divided&&<MetricHint label={outcome.label}/>}</span>{!countFirst&&<strong className="shrink-0" style={{color:`var(--delivery-${outcome.key})`}}>{(counts[index]??0).toLocaleString()}</strong>}
      {total!==undefined&&<span className="w-12 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{total>0?((counts[index]??0)/total*100).toFixed(1):'0'}%</span>}
    </div>;
    return tooltips?<Tooltip key={outcome.key} disableHoverableContent={divided} delayDuration={divided?0:undefined}><TooltipTrigger asChild>{row}</TooltipTrigger><TooltipContent side={divided?'right':'top'} collisionPadding={16} className={`max-w-[min(18rem,calc(100vw-2rem))] ${divided?'data-[state=closed]:hidden':''}`}>{outcome.detail}</TooltipContent></Tooltip>:<div key={outcome.key}>{row}</div>;
  })}</div>;
}
