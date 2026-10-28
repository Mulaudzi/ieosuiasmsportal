export interface ReportingPeriod { range: string; from?: string; to?: string }
export const defaultDashboardPeriod: ReportingPeriod = { range: '30d' };
export function validateCustomPeriod(from: string, to: string): string | null {
  const valid = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0,10) === value;
  if (!valid(from) || !valid(to)) return 'Choose valid start and end dates.';
  if (from > to) return 'The end date must be on or after the start date.';
  if ((Date.parse(to)-Date.parse(from))/86400000 >= 366) return 'Choose a date range of up to 366 days.';
  return null;
}
export function periodLabel(period: ReportingPeriod): string {
  if (period.range === 'custom') return `${period.from} – ${period.to}`;
  return ({ '24h':'Last 24 hours', '7d':'Last 7 days', '30d':'Last 30 days', '90d':'Last 90 days', all:'All dates' })[period.range] ?? 'Last 30 days';
}
