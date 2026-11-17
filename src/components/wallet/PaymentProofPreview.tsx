import { lazy, Suspense, useEffect, useState } from 'react';
import { api, downloadAuthenticated, handleApiError } from '@/lib/api';
import { PurchaseOrder } from '@/lib/credit-purchases';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const PdfProofPreview = lazy(() => import('./PdfProofPreview'));
type Proof = PurchaseOrder['proofs'][number];

/** Fetch with the current session; never expose credentials in a document URL. */
export function PaymentProofPreview({ proof, label, reference }: { proof: Proof; label: string; reference: string }) {
  const [open, setOpen] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [document, setDocument] = useState<{ blob: Blob; imageUrl?: string } | null>(null);
  const [error, setError] = useState('');
  const endpoint = `/wallet/payment-proofs/${proof.id}`;

  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    let active = true;
    let imageUrl: string | undefined;
    setDocument(null); setError('');
    void api.getBlob(endpoint, controller.signal).then(blob => {
      if (!active) return;
      if (!['application/pdf', 'image/jpeg', 'image/png'].includes(blob.type) || !blob.size || blob.size > 5 * 1024 * 1024) {
        throw new Error('This document cannot be previewed. Only PDF, JPG and PNG files up to 5 MB are supported.');
      }
      if (blob.type !== 'application/pdf') imageUrl = URL.createObjectURL(blob);
      setDocument({ blob, imageUrl });
    }).catch((reason: unknown) => {
      if (active) setError(reason instanceof Error ? reason.message : 'Could not load this proof of payment.');
    });
    return () => { active = false; controller.abort(); if (imageUrl) URL.revokeObjectURL(imageUrl); };
  }, [open, endpoint, attempt]);

  const changeOpen = (value: boolean) => { setOpen(value); if (!value) { setDocument(null); setError(''); } };
  return <>
    <Button variant="outline" onClick={() => changeOpen(true)}>{label}</Button>
    <Dialog open={open} onOpenChange={changeOpen}>
      <DialogContent className="flex max-h-[90dvh] w-[calc(100%-2rem)] max-w-4xl flex-col overflow-hidden p-4 sm:p-6">
        <DialogHeader className="pr-6">
          <DialogTitle>Proof of payment</DialogTitle>
          <DialogDescription className="break-all">{reference}. Check cleared funds in your bank account before approving credits.</DialogDescription>
        </DialogHeader>
        <div className="min-h-32 min-w-0 overflow-auto rounded-md border bg-muted/30 p-2">
          {error ? <div role="alert" className="space-y-3 p-3"><p>{error}</p><Button variant="outline" onClick={() => setAttempt(value => value + 1)}>Retry preview</Button></div>
            : !document ? <p role="status" className="p-3">Loading proof of payment...</p>
            : document.imageUrl ? <img src={document.imageUrl} alt={`Proof of payment for ${reference}`} className="mx-auto h-auto max-w-full" onError={() => setError('This image could not be displayed. Please ask for a new copy.')} />
            : <Suspense fallback={<p role="status">Loading PDF viewer...</p>}><PdfProofPreview blob={document.blob} /></Suspense>}
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" onClick={() => void downloadAuthenticated(endpoint, `payment-proof-${proof.id}.${proof.mime_type === 'application/pdf' ? 'pdf' : proof.mime_type === 'image/png' ? 'png' : 'jpg'}`).catch(handleApiError)}>Download copy</Button>
          <Button variant="outline" onClick={() => changeOpen(false)}>Close preview</Button>
        </div>
      </DialogContent>
    </Dialog>
  </>;
}
