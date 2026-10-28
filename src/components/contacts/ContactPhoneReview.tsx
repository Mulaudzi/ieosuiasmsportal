import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { analyzeSouthAfricanPhone, PhoneDecision } from "@/lib/contact-phone-review";

interface Props {
  phones: string[];
  rowNumbers: number[];
  decisions: Record<number, PhoneDecision>;
  onChange: (decisions: Record<number, PhoneDecision>) => void;
}

export function ContactPhoneReview({ phones, rowNumbers, decisions, onChange }: Props) {
  const issues = useMemo(() => phones.flatMap((phone, index) => {
    const analysis = analyzeSouthAfricanPhone(phone);
    return analysis.normalized ? [] : [{ index, original: phone, ...analysis }];
  }), [phones]);
  const [edits, setEdits] = useState<Record<number, string>>({});
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [page, setPage] = useState(0);
  const valueFor = (issue: typeof issues[number]) => edits[issue.index] ?? decisions[issue.index]?.value ?? issue.proposed;
  const candidateCounts = new Map<string, number>();
  phones.forEach((phone, index) => {
    if (decisions[index]?.action === "reject") return;
    const analysis = analyzeSouthAfricanPhone(phone);
    const normalized = analysis.normalized ?? analyzeSouthAfricanPhone(edits[index] ?? decisions[index]?.value ?? analysis.proposed).normalized;
    if (normalized) candidateCounts.set(normalized, (candidateCounts.get(normalized) ?? 0) + 1);
  });
  if (!issues.length) return null;
  const pending = issues.filter(issue => !decisions[issue.index]).length;
  const accepted = issues.filter(issue => decisions[issue.index]?.action === "accept").length;
  const rejected = issues.filter(issue => decisions[issue.index]?.action === "reject").length;
  const accept = (indexes: Set<number>) => {
    const next = { ...decisions };
    issues.filter(issue => indexes.has(issue.index)).forEach(issue => {
      const value = valueFor(issue);
      if (analyzeSouthAfricanPhone(value).normalized) next[issue.index] = { action: "accept", original: issue.original, value };
    });
    onChange(next);
  };
  const reject = (indexes: Set<number>) => {
    const next = { ...decisions };
    issues.filter(issue => indexes.has(issue.index)).forEach(issue => {
      next[issue.index] = { action: "reject", original: issue.original, value: valueFor(issue) };
    });
    onChange(next);
  };
  const canAccept = issues.some(issue => analyzeSouthAfricanPhone(valueFor(issue)).normalized && decisions[issue.index]?.action !== "accept");
  const allSelected = issues.every(issue => selected.has(issue.index));
  const pages = Math.ceil(issues.length / 25);

  return <section aria-label="Phone number reconstruction" className="min-w-0 space-y-3 rounded-lg border border-border p-3">
    <div className="space-y-1">
      <h3 className="font-semibold">Review phone numbers</h3>
      <p className="text-sm text-muted-foreground">Compare the original number with the proposed correction. Accept only numbers you recognise. Nothing is corrected automatically.</p>
      <p className="text-xs text-muted-foreground">South African mobile formats: 0821234567, 27821234567 or +27821234567. A valid format does not confirm that a number is active or belongs to the intended person.</p>
      <p role="status" className="text-sm">{pending} awaiting your choice · {accepted} corrections accepted · {rejected} rows rejected</p>
    </div>
    <div className="flex flex-wrap items-center gap-2">
      <Checkbox id="select-review-numbers" checked={allSelected ? true : selected.size ? "indeterminate" : false} onCheckedChange={checked => setSelected(checked === true ? new Set(issues.map(issue => issue.index)) : new Set())} />
      <label htmlFor="select-review-numbers" className="text-sm">Select all {issues.length} flagged rows</label>
    </div>
    <div className="flex flex-wrap gap-2">
      <Button size="sm" variant="outline" disabled={!canAccept} onClick={() => accept(new Set(issues.map(issue => issue.index)))}>Accept all proposals</Button>
      <Button size="sm" variant="outline" disabled={!issues.some(issue => selected.has(issue.index) && analyzeSouthAfricanPhone(valueFor(issue)).normalized)} onClick={() => accept(selected)}>Accept selected ({selected.size})</Button>
      <Button size="sm" variant="outline" disabled={!selected.size} onClick={() => reject(selected)}>Reject selected ({selected.size})</Button>
    </div>
    <p className="text-xs text-muted-foreground">Accept all applies only valid proposals, including your edits. Rows without a safe proposal need an edit or rejection. Rejected rows will not be imported; saved contacts are never deleted.</p>
    <div className="space-y-3">
      {issues.slice(page * 25, (page + 1) * 25).map(issue => {
        const row = rowNumbers[issue.index] ?? issue.index + 2;
        const value = valueFor(issue);
        const normalized = analyzeSouthAfricanPhone(value).normalized;
        const decision = decisions[issue.index];
        return <div key={issue.index} data-review-row={row} className="min-w-0 space-y-2 rounded-md border border-border p-3">
          <div className="flex items-center gap-2">
            <Checkbox aria-label={`Select row ${row}`} checked={selected.has(issue.index)} onCheckedChange={checked => setSelected(previous => { const next = new Set(previous); if (checked === true) next.add(issue.index); else next.delete(issue.index); return next; })} />
            <span className="text-sm font-medium">Row {row}</span>
            <span className="ml-auto text-xs font-medium">{decision?.action === "accept" ? "Accepted" : decision?.action === "reject" ? "Rejected" : "Needs review"}</span>
          </div>
          <div className="grid min-w-0 gap-3 sm:grid-cols-2">
            <div className="min-w-0"><p className="text-xs text-muted-foreground">Original number</p><p className="break-all text-sm font-medium">{issue.original || "Empty"}</p></div>
            <div className="min-w-0 space-y-1">
              <label className="text-xs text-muted-foreground" htmlFor={`phone-proposal-${issue.index}`}>Proposed correction</label>
              <Input id={`phone-proposal-${issue.index}`} aria-label={`Proposed correction for row ${row}`} type="tel" maxLength={50} value={value} placeholder="Enter the correct number" onChange={event => {
                setEdits(previous => ({ ...previous, [issue.index]: event.target.value }));
                const next = { ...decisions }; delete next[issue.index]; onChange(next);
              }} />
              {value && !normalized && <p className="text-xs text-destructive">Enter a complete South African mobile number.</p>}
            </div>
          </div>
          <p className="text-xs text-muted-foreground">{issue.reason}</p>
          {normalized && (candidateCounts.get(normalized) ?? 0) > 1 && <p className="text-xs text-muted-foreground">Matches another number in this file. Duplicate checking will keep one contact.</p>}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant={decision?.action === "accept" ? "secondary" : "outline"} aria-label={`Accept correction for row ${row}`} disabled={!normalized || decision?.action === "accept"} onClick={() => accept(new Set([issue.index]))}>Accept</Button>
            <Button size="sm" variant="outline" aria-label={`Reject row ${row}`} disabled={decision?.action === "reject"} onClick={() => reject(new Set([issue.index]))}>Reject</Button>
          </div>
        </div>;
      })}
    </div>
    {pages > 1 && <div className="flex items-center justify-between gap-2 text-sm">
      <Button size="sm" variant="outline" disabled={page === 0} onClick={() => setPage(page - 1)}>Previous rows</Button>
      <span>{page + 1} / {pages}</span>
      <Button size="sm" variant="outline" disabled={page + 1 === pages} onClick={() => setPage(page + 1)}>Next rows</Button>
    </div>}
    {pending > 0 && <p className="text-sm text-destructive">Accept or reject all flagged rows before importing.</p>}
  </section>;
}
