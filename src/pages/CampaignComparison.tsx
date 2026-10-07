import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { format } from "date-fns";
import { ChevronDown, ChevronUp, Clock, Crown, DollarSign, Loader2, Search, X } from "lucide-react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useAuth } from "@/hooks/useAuth";
import { api } from "@/lib/api";
import { cn } from "@/lib/utils";
import {
  ComparisonCampaign,
  comparisonWinners,
  confirmationTime,
  costPerDelivery,
  sentenceCase,
} from "@/lib/campaign-comparison";

interface ComparisonResponse { campaigns: ComparisonCampaign[] }

const names = (campaigns: ComparisonCampaign[]) => campaigns.map(c => sentenceCase(c.name)).join(", ");
const winnerCaption = (campaigns: ComparisonCampaign[]) =>
  campaigns.length ? `${campaigns.length > 1 ? "Tied: " : ""}${names(campaigns)}` : "No qualifying campaign";
const campaignDate = (date: string) => {
  const parsed = new Date(date);
  return Number.isNaN(parsed.getTime()) ? "Date unavailable" : format(parsed, "d MMM yyyy");
};

export default function CampaignComparison() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(false);
  const [loadingCampaigns, setLoadingCampaigns] = useState(true);
  const [availableCampaigns, setAvailableCampaigns] = useState<ComparisonCampaign[]>([]);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [campaigns, setCampaigns] = useState<ComparisonCampaign[] | null>(null);
  const [pickerOpen, setPickerOpen] = useState(true);
  const [typeFilter, setTypeFilter] = useState("all");
  const [search, setSearch] = useState("");
  const [error, setError] = useState("");
  const [selectionError, setSelectionError] = useState("");
  const [selectionReload, setSelectionReload] = useState(0);
  const requestSequence = useRef(0);
  const resultsTop = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let active = true;
    requestSequence.current++;
    setCampaigns(null);
    setSelectedIds([]);
    setAvailableCampaigns([]);
    setPickerOpen(true);
    setLoading(false);
    setError("");
    setSelectionError("");
    setLoadingCampaigns(true);
    api.get<ComparisonResponse>("/reports/compare", { selection: "1" })
      .then(res => {
        const payload = (res.data ?? res) as ComparisonResponse;
        if (!res.success || !Array.isArray(payload.campaigns)) throw new Error("Invalid campaign list");
        if (active) setAvailableCampaigns(payload.campaigns.map(c => ({ ...c, id: String(c.id) })));
      })
      .catch(() => { if (active) setSelectionError("Could not load campaigns. Please try again."); })
      .finally(() => { if (active) setLoadingCampaigns(false); });
    return () => { active = false; requestSequence.current++; };
  }, [user?.id, selectionReload]);

  const loadComparison = async (ids: string[]) => {
    if (ids.length < 2 || ids.length > 5) return;
    const sequence = ++requestSequence.current;
    setLoading(true);
    setError("");
    // Do not display figures for an old selection while a new selection is loading.
    setCampaigns(null);
    try {
      const res = await api.get<ComparisonResponse>("/reports/compare", { ids: ids.join(",") });
      const payload = (res.data ?? res) as ComparisonResponse;
      if (!res.success || !Array.isArray(payload.campaigns)) throw new Error("Invalid comparison");
      const byId = new Map(payload.campaigns.map(c => [String(c.id), { ...c, id: String(c.id) }]));
      if (payload.campaigns.length !== ids.length || byId.size !== ids.length || ids.some(id => !byId.has(id))) {
        throw new Error("Incomplete comparison");
      }
      if (sequence !== requestSequence.current) return;
      const rows = ids.map(id => byId.get(id)!);
      setCampaigns(rows);
      // Keep the picker counts in sync with the freshly read message records.
      setAvailableCampaigns(current => current.map(c => byId.get(c.id) ?? c));
      setSelectedIds(ids);
      setPickerOpen(false);
      resultsTop.current?.scrollIntoView({ block: "start", behavior: "auto" });
    } catch {
      if (sequence !== requestSequence.current) return;
      setPickerOpen(true);
      setError("Could not compare the selected campaigns. Check your selection and try again.");
    } finally {
      if (sequence === requestSequence.current) setLoading(false);
    }
  };

  const toggleCampaign = (id: string) => {
    setSelectedIds(current => current.includes(id)
      ? current.filter(selected => selected !== id)
      : current.length < 5 ? [...current, id] : current);
  };

  const removeCampaign = (id: string) => {
    const remaining = (campaigns ?? []).map(c => c.id).filter(selected => selected !== id);
    setSelectedIds(remaining);
    setError("");
    if (remaining.length < 2) {
      requestSequence.current++;
      setCampaigns(null);
      setLoading(false);
      setPickerOpen(true);
    } else {
      void loadComparison(remaining);
    }
  };

  const filteredCampaigns = availableCampaigns.filter(c =>
    (typeFilter === "all" || c.type === typeFilter) &&
    c.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())
  );
  const winners = comparisonWinners(campaigns ?? []);
  const metrics = [
    { label: "Sent", value: (c: ComparisonCampaign) => c.sent_count.toLocaleString() },
    { label: "Delivered", value: (c: ComparisonCampaign) => c.delivered_count.toLocaleString() },
    { label: "Not delivered", value: (c: ComparisonCampaign) => c.failed_count.toLocaleString() },
    { label: "Pending", value: (c: ComparisonCampaign) => c.pending_count.toLocaleString(), muted: true },
    { label: "Delivery rate", value: (c: ComparisonCampaign) => c.delivery_rate === null ? "—" : `${c.delivery_rate}%`, winners: winners.rate },
    { label: "Confirmation time", value: (c: ComparisonCampaign) => c.delivered_count > 0 ? confirmationTime(c.avg_delivery_time_seconds) : "—", winners: winners.time },
    { label: "Cost per delivery", value: (c: ComparisonCampaign) => c.delivered_count > 0 ? costPerDelivery(c.cost_per_delivery) : "—", winners: winners.cost },
  ];

  return (
    <DashboardLayout
      title="Campaign comparison"
      subtitle="Compare delivery, time, and cost across 2 to 5 campaigns."
      actions={<Link to="/reports" className="ml-auto text-sm font-medium text-primary underline-offset-4 hover:underline">Back to Reports</Link>}
    >
      <div ref={resultsTop} className="min-w-0 scroll-mt-24 space-y-4" aria-busy={loading}>
        {loading && <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 aria-hidden="true" className="h-4 w-4 animate-spin" />Updating comparison…</p>}
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}

        {campaigns && (
          <section aria-label="Comparison results" className="min-w-0 space-y-4">
            <div className="flex flex-wrap gap-2" aria-label="Selected campaigns">
              {campaigns.map(campaign => (
                <span key={campaign.id} className="inline-flex max-w-full items-center gap-2 rounded-full border border-border bg-card py-1 pl-3 pr-1 text-sm">
                  <span className="min-w-0 break-words">{sentenceCase(campaign.name)}</span>
                  <button type="button" aria-label={`Remove ${sentenceCase(campaign.name)}`} onClick={() => removeCampaign(campaign.id)} className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <X aria-hidden="true" className="h-4 w-4" />
                  </button>
                </span>
              ))}
            </div>

            <div className="grid gap-4 md:grid-cols-3">
              <div className="min-w-0 rounded-xl border border-success/30 bg-success/5 p-4">
                <h2 className="mb-2 flex items-center gap-2 text-sm font-medium"><Crown aria-hidden="true" className="h-5 w-5 shrink-0 text-success" />Best delivery rate</h2>
                <p className={cn("text-2xl font-bold", winners.rate.length ? "text-success" : "text-muted-foreground")}>{winners.rate.length ? `${winners.rate[0].delivery_rate}%` : "—"}</p>
                <p className="mt-1 break-words text-sm text-muted-foreground">{winnerCaption(winners.rate)}</p>
                {winners.rate.length === 1 && <p className="mt-2 text-xs text-muted-foreground">Based on {winners.rate[0].sent_count.toLocaleString()} {winners.rate[0].sent_count === 1 ? "message" : "messages"}.</p>}
                {winners.rate.length > 1 && <p className="mt-2 break-words text-xs text-muted-foreground">Based on {winners.rate.map(c => `${sentenceCase(c.name)}: ${c.sent_count.toLocaleString()} ${c.sent_count === 1 ? "message" : "messages"}`).join("; ")}.</p>}
              </div>

              <div className="min-w-0 rounded-xl border border-primary/30 bg-primary/5 p-4">
                <h2 className="mb-2 flex items-center gap-2 text-sm font-medium"><Clock aria-hidden="true" className="h-5 w-5 shrink-0 text-primary" />Shortest confirmation time</h2>
                <p className={cn("text-2xl font-bold", winners.time.length ? "text-primary" : "text-muted-foreground")}>{confirmationTime(winners.time[0]?.avg_delivery_time_seconds ?? null)}</p>
                <p className="mt-1 break-words text-sm text-muted-foreground">{winnerCaption(winners.time)}</p>
              </div>

              <div className="min-w-0 rounded-xl border border-accent/30 bg-accent/5 p-4">
                <h2 className="mb-2 flex items-center gap-2 text-sm font-medium"><DollarSign aria-hidden="true" className="h-5 w-5 shrink-0 text-accent" />Best value</h2>
                <p className={cn("text-2xl font-bold", costPerDelivery(winners.cost[0]?.cost_per_delivery ?? null) !== "—" ? "text-accent" : "text-muted-foreground")}>{costPerDelivery(winners.cost[0]?.cost_per_delivery ?? null)}</p>
                <p className="mt-1 break-words text-sm text-muted-foreground">per delivered message{": "}{winnerCaption(winners.cost)}</p>
              </div>
            </div>

            <p className="text-sm text-muted-foreground">Confirmed deliveries: {names(campaigns.filter(c => c.delivered_count > 0)) || "none"}; still pending: {names(campaigns.filter(c => c.pending_count > 0)) || "none"}.</p>

            <div className="min-w-0 overflow-hidden rounded-xl border border-border bg-card">
              <div className="border-b border-border px-4 py-3"><h2 id="detailed-comparison" className="font-semibold text-foreground">Detailed comparison</h2></div>
              <div className="overflow-x-auto" tabIndex={0} role="region" aria-labelledby="detailed-comparison">
                <table className="w-full table-fixed" style={{ minWidth: 160 + campaigns.length * 220 }} aria-label="Detailed comparison">
                  <thead>
                    <tr className="border-b border-border bg-muted/50">
                      <th scope="col" className="w-40 px-4 py-3 text-left text-sm font-medium text-muted-foreground">Metric</th>
                      {campaigns.map(campaign => (
                        <th scope="col" key={campaign.id} className="whitespace-normal break-words px-4 py-3 text-center text-sm font-medium text-foreground">
                          {sentenceCase(campaign.name)}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {metrics.map(metric => (
                      <tr key={metric.label}>
                        <th scope="row" className="px-4 py-3 text-left text-sm font-normal text-muted-foreground">{metric.label}</th>
                        {campaigns.map(campaign => {
                          const value = metric.value(campaign);
                          const muted = metric.muted || value === "—";
                          const winner = !muted && metric.winners?.some(c => c.id === campaign.id);
                          return <td key={campaign.id} className={cn("px-4 py-3 text-center text-sm font-medium", muted ? "text-muted-foreground" : winner ? "bg-accent/10 font-semibold text-accent" : "text-foreground")}>{value}</td>;
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        )}

        <section className="min-w-0 rounded-xl border border-border bg-card" aria-label="Campaign selection">
          {campaigns && <button type="button" aria-expanded={pickerOpen} aria-controls="campaign-picker" onClick={() => setPickerOpen(!pickerOpen)} className="flex w-full items-center justify-between gap-2 rounded-xl px-4 py-3 text-left text-sm font-medium text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            Change campaigns{pickerOpen ? <ChevronUp aria-hidden="true" className="h-4 w-4" /> : <ChevronDown aria-hidden="true" className="h-4 w-4" />}
          </button>}
          {(!campaigns || pickerOpen) && (
            <div id="campaign-picker" className="space-y-4 p-4">
              {!campaigns && <h2 className="font-semibold text-foreground">Select campaigns</h2>}
              <div className="flex flex-wrap items-center gap-3">
                <div className="relative min-w-0 flex-1 basis-48">
                  <Search aria-hidden="true" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input aria-label="Search campaigns" placeholder="Search campaigns" value={search} onChange={event => setSearch(event.target.value)} className="pl-9" />
                </div>
                <Select value={typeFilter} onValueChange={setTypeFilter}>
                  <SelectTrigger className="w-32" aria-label="Campaign type"><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="all">All Types</SelectItem><SelectItem value="sms">SMS</SelectItem></SelectContent>
                </Select>
              </div>

              {loadingCampaigns ? <p role="status" className="py-4 text-center text-sm text-muted-foreground">Loading campaigns…</p> : selectionError ? (
                <div className="space-y-2"><p role="alert" className="text-sm text-destructive">{selectionError}</p><Button variant="outline" onClick={() => setSelectionReload(value => value + 1)}>Retry</Button></div>
              ) : (
                <div className="max-h-72 space-y-2 overflow-y-auto">
                  {filteredCampaigns.map(campaign => {
                    const selected = selectedIds.includes(campaign.id);
                    const disabled = loading || (!selected && selectedIds.length >= 5);
                    return <label key={campaign.id} className={cn("flex items-center gap-3 rounded-lg border p-3 transition-colors", selected ? "border-primary bg-primary/5" : "border-border", disabled ? "cursor-not-allowed opacity-60" : "cursor-pointer hover:border-primary/50")}>
                      <Checkbox checked={selected} disabled={disabled} onCheckedChange={() => toggleCampaign(campaign.id)} aria-label={sentenceCase(campaign.name)} />
                      <span className="min-w-0 flex-1">
                        <span className="block break-words text-sm font-medium text-foreground">{sentenceCase(campaign.name)}</span>
                        <span className="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground"><span>{campaignDate(campaign.created_at)}</span><span>{campaign.sent_count.toLocaleString()} sent</span></span>
                      </span>
                    </label>;
                  })}
                  {!filteredCampaigns.length && <p className="py-6 text-center text-sm text-muted-foreground">{availableCampaigns.length ? "No campaigns match your search or filter." : "No campaigns yet."}</p>}
                </div>
              )}
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-xs text-muted-foreground">{selectedIds.length} of 5 selected</p>
                <Button onClick={() => void loadComparison([...selectedIds])} disabled={loading || loadingCampaigns || selectedIds.length < 2 || selectedIds.length > 5} className="gap-2">
                  {loading && <Loader2 aria-hidden="true" className="h-4 w-4 animate-spin" />}Compare ({selectedIds.length})
                </Button>
              </div>
            </div>
          )}
        </section>
      </div>
    </DashboardLayout>
  );
}
