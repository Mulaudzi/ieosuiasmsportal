export interface ApiCampaignAttribution {
  type: 'api';
  connection_name: string;
  reference: string;
  external_reference: string;
}

/** Account-facing attribution only. Labels render as escaped plain text. */
export function ApiCampaignSource({ source, details = false }: { source?: ApiCampaignAttribution; details?: boolean }) {
  if (!source || source.type !== 'api') return null;
  return <div className="my-2 min-w-0 space-y-1 break-words text-xs text-muted-foreground" aria-label="API campaign source">
    <p><span className="mr-2 inline-flex rounded-full border border-primary/20 bg-primary/5 px-2 py-0.5 font-medium text-primary">API</span>{source.connection_name}</p>
    {details && <><p className="break-all">API reference: {source.reference}</p>{source.external_reference && <p>Application reference: {source.external_reference}</p>}</>}
  </div>;
}
