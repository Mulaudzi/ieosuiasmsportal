import { format } from 'date-fns';
export const portalDate = (value?: string) => {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : format(date, 'd MMM yyyy, HH:mm');
};
export function typicalTime(seconds: number | null) {
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return null;
  if (seconds < 5400) return `${Math.max(1, Math.min(89, Math.round(seconds / 60)))} min`;
  if (seconds < 172800) return `${Math.round(seconds / 3600 * 10) / 10} hrs`;
  return `${Math.round(seconds / 86400)} days`;
}
