export interface ComparisonCampaign {
  id: string;
  name: string;
  type: string;
  created_at: string;
  sent_count: number;
  delivered_count: number;
  failed_count: number;
  pending_count: number;
  delivery_rate: number | null;
  actual_cost: number;
  avg_delivery_time_seconds: number | null;
  cost_per_delivery: number | null;
}

// Presentation only: the stored campaign name is never changed.
export const sentenceCase = (name: string) =>
  name ? name.charAt(0).toLocaleUpperCase() + name.slice(1).toLocaleLowerCase() : name;

export function confirmationTime(seconds: number | null): string {
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return "—";
  if (seconds < 5400) return `${Math.max(1, Math.round(seconds / 60))} min`;
  if (seconds < 172800) {
    const hours = Math.round(seconds / 3600);
    return `${hours} ${hours === 1 ? "hour" : "hours"}`;
  }
  const days = Math.round(seconds / 86400);
  return `${days} ${days === 1 ? "day" : "days"}`;
}

export function costPerDelivery(value: number | null): string {
  if (value === null || !Number.isFinite(value) || value <= 0) return "—";
  return value < 0.01 ? "Less than R0.01" : `R${value.toFixed(2)}`;
}

export function comparisonWinners(campaigns: ComparisonCampaign[]) {
  const best = (
    items: ComparisonCampaign[],
    value: (campaign: ComparisonCampaign) => number,
    highest = false,
  ) => {
    const eligible = items.filter(c => Number.isFinite(value(c)));
    if (!eligible.length) return [];
    const winning = (highest ? Math.max : Math.min)(...eligible.map(value));
    return eligible.filter(c => Math.abs(value(c) - winning) < 1e-9);
  };
  return {
    rate: best(campaigns.filter(c => c.sent_count > 0 && c.delivery_rate !== null), c => c.delivery_rate!, true),
    time: best(campaigns.filter(c => c.delivered_count > 0 && c.avg_delivery_time_seconds !== null && c.avg_delivery_time_seconds >= 0), c => c.avg_delivery_time_seconds!),
    cost: best(campaigns.filter(c => c.delivered_count > 0 && c.cost_per_delivery !== null && c.cost_per_delivery >= 0), c => c.cost_per_delivery!),
  };
}
