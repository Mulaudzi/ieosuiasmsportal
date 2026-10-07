/** Presentation only: the API remains responsible for calculating the average. */
export function formatDeliveryTime(value: string | number | null | undefined): string {
  if(value == null || value === '' || value === 'N/A')return 'N/A';
  const match=String(value).match(/^\s*(\d+(?:\.\d+)?)\s*s?\s*$/);
  if(!match)return String(value);
  const seconds=Number(match[1]);
  return seconds<60?'Under 1 min':`${Math.round(seconds/60)} min`;
}
