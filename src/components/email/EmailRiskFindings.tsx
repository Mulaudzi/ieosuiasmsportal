import {EmailRiskReport} from '@/lib/email-campaigns';
export function EmailRiskFindings({report}:{report?:EmailRiskReport|null}){
 if(!report)return null;
 return <section className="rounded-xl border bg-muted/30 p-4 text-sm" aria-label="Automated content checks"><h3 className="font-semibold">{report.blocked?'Content needs changes':report.findings.length?'Content needs review':'Automated content checks completed'}</h3>{report.findings.length>0&&<ul className="mt-2 list-disc space-y-2 pl-5">{report.findings.map(v=><li key={v.code}><strong>{v.severity==='block'?'Change required':'Review'}:</strong> {v.message}</li>)}</ul>}{report.links.length>0&&<details className="mt-3"><summary>Destinations to inspect ({report.links.length})</summary><ul className="mt-2 space-y-1">{report.links.map(v=><li key={v} className="break-all font-mono text-xs">{v}</li>)}</ul></details>}<p className="mt-3 text-xs text-muted-foreground">{report.limitations}</p></section>;
}
