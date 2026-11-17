import { useEffect, useRef, useState } from 'react';
import { getDocument, GlobalWorkerOptions, type PDFDocumentProxy } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { Button } from '@/components/ui/button';

// Same-origin worker, no remote document service, embedded scripts or iframe.
GlobalWorkerOptions.workerSrc = workerUrl;

export default function PdfProofPreview({ blob }: { blob: Blob }) {
  const [pdf, setPdf] = useState<PDFDocumentProxy | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const canvas = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    let active = true;
    let task: ReturnType<typeof getDocument> | undefined;
    setPdf(null); setPage(1); setLoading(true); setError('');
    void blob.arrayBuffer().then(data => {
      if (!active) return;
      task = getDocument({ data: new Uint8Array(data), useSystemFonts: true, enableXfa: false });
      return task.promise;
    }).then(document => { if (active && document) setPdf(document); }).catch(() => {
      if (active) { setError('This PDF could not be opened. It may be damaged or password protected. Please request an unlocked PDF or an image copy.'); setLoading(false); }
    });
    return () => { active = false; void task?.destroy(); };
  }, [blob]);

  useEffect(() => {
    if (!pdf) return;
    let active = true;
    let render: ReturnType<Awaited<ReturnType<PDFDocumentProxy['getPage']>>['render']> | undefined;
    setLoading(true); setError('');
    void pdf.getPage(page).then(documentPage => {
      if (!active || !canvas.current) return;
      const original = documentPage.getViewport({ scale: 1 });
      const scale = Math.min(2, 1400 / original.width, Math.sqrt(4000000 / (original.width * original.height)));
      const viewport = documentPage.getViewport({ scale });
      canvas.current.width = Math.ceil(viewport.width); canvas.current.height = Math.ceil(viewport.height);
      render = documentPage.render({ canvas: canvas.current, viewport });
      return render.promise;
    }).then(() => { if (active) setLoading(false); }).catch(() => {
      if (active) { setLoading(false); setError('This PDF page could not be displayed. Please request a new copy.'); }
    });
    return () => { active = false; render?.cancel(); };
  }, [pdf, page]);

  return <div className="space-y-3">
    {pdf && <div className="flex flex-wrap items-center justify-center gap-2">
      <Button variant="outline" disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)}>Previous page</Button>
      <span className="text-sm">Page {page} of {pdf.numPages}</span>
      <Button variant="outline" disabled={page >= pdf.numPages || loading} onClick={() => setPage(value => value + 1)}>Next page</Button>
    </div>}
    {loading && <p role="status">Rendering PDF page...</p>}
    {error && <p role="alert">{error}</p>}
    <canvas ref={canvas} role="img" aria-label={`Payment proof PDF, page ${page}`} className={`mx-auto h-auto max-w-full ${loading || error ? 'hidden' : ''}`} />
  </div>;
}
