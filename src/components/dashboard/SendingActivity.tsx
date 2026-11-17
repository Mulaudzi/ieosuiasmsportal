import { Cloud, FileText, Send, Sparkles } from 'lucide-react';

/** Decorative motion is separate from the server-backed counters and progress. */
export function SendingActivity({preparing,queued}:{preparing:boolean;queued:boolean}) {
  return <div className="sending-activity" data-stage={preparing?'preparing':'sending'}>
    <div className="sending-activity__visual" aria-hidden="true">
      <span className="sending-activity__node"><FileText size={19}/></span>
      <div className="sending-activity__runway"><span className="sending-activity__trail"/><span className="sending-activity__plane"><Send size={21} strokeWidth={1.8}/></span><i/><i/><i/></div>
      <span className="sending-activity__node sending-activity__node--gateway"><Cloud size={22}/><Sparkles className="sending-activity__sparkle" size={12}/></span>
    </div>
    <div className="sending-activity__copy"><p role="status" aria-live="polite" aria-atomic="true">{preparing?'Waiting for the first sending result':'Your campaign is on the move'}<span className="sending-activity__ellipsis" aria-hidden="true"><i/><i/><i/></span></p><span>{preparing?queued?'Your campaign is queued. Progress updates here when sending begins.':'A little runway before take-off. Confirmed results will appear here automatically.':'Each confirmed result moves the bar forward. Phone delivery is tracked separately.'}</span></div>
  </div>;
}
