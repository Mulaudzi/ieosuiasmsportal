import { deliveryVisibility } from '@/lib/delivery-visibility';

export function DeliveryResultsPending({counts,total,compact=false}:{counts:readonly number[];total:number;compact?:boolean}) {
  const visibility=deliveryVisibility(counts,total);
  return <div role="status" className={compact?'max-w-64 text-xs text-muted-foreground':'rounded-lg border border-border bg-muted/30 p-4 text-sm text-muted-foreground'}>
    <p className="font-medium text-foreground">{visibility.title}</p>
    <p className={compact?'mt-1':'mt-2'}>{visibility.message}</p>
  </div>;
}
