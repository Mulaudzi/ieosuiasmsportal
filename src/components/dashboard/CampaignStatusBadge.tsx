import { campaignStatus } from '@/lib/campaign-status';
export function CampaignStatusBadge({campaign}:{campaign:Parameters<typeof campaignStatus>[0]}) {
 const status=campaignStatus(campaign);
 return <span className={`status-badge gap-1.5 ${status.class}`}><span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"/>{status.label}</span>;
}
