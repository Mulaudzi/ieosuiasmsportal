import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { PaymentProofPreview } from './PaymentProofPreview';

const mocks = vi.hoisted(() => ({ getBlob: vi.fn(), download: vi.fn(), revoke: vi.fn(), create: vi.fn() }));
vi.mock('@/lib/api', () => ({ api: { getBlob: mocks.getBlob }, downloadAuthenticated: mocks.download, handleApiError: vi.fn() }));
vi.mock('./PdfProofPreview', () => ({ default: () => <div>PDF rendered inside the portal</div> }));
const proof = {id:9,mime_type:'image/png',byte_size:123,created_at:'2026-10-21'};
function mount() { return render(<PaymentProofPreview proof={proof} reference="SMS-TEST" label="View proof 1" />); }
beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('URL', Object.assign(URL, {createObjectURL:mocks.create,revokeObjectURL:mocks.revoke}));
  mocks.create.mockReturnValue('blob:private-image');
  mocks.getBlob.mockResolvedValue(new Blob(['image'],{type:'image/png'}));
  mocks.download.mockResolvedValue({success:true});
});
afterEach(() => {cleanup();vi.unstubAllGlobals();});
it('opens a private image preview without downloading and frees its URL on close', async () => {
  mount(); expect(mocks.getBlob).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button',{name:'View proof 1'}));
  expect(await screen.findByRole('img',{name:'Proof of payment for SMS-TEST'})).toHaveAttribute('src','blob:private-image');
  expect(mocks.getBlob).toHaveBeenCalledWith('/wallet/payment-proofs/9',expect.any(AbortSignal));
  expect(mocks.download).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button',{name:'Close preview'}));
  await waitFor(() => expect(mocks.revoke).toHaveBeenCalledWith('blob:private-image'));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('only downloads when explicitly requested', async () => {
  mount();fireEvent.click(screen.getByRole('button',{name:'View proof 1'}));
  await screen.findByRole('img');
  fireEvent.click(screen.getByRole('button',{name:'Download copy'}));
  expect(mocks.download).toHaveBeenCalledWith('/wallet/payment-proofs/9','payment-proof-9.png');
});
it('rejects HTML instead of displaying an untrusted response and lets the user retry', async () => {
  mocks.getBlob.mockResolvedValueOnce(new Blob(['<script>bad</script>'],{type:'text/html'}));
  mount();fireEvent.click(screen.getByRole('button',{name:'View proof 1'}));
  expect(await screen.findByRole('alert')).toHaveTextContent('cannot be previewed');
  expect(mocks.create).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button',{name:'Retry preview'}));
  await screen.findByRole('img');
});
it('shows permission errors without creating a document URL', async () => {
  mocks.getBlob.mockRejectedValueOnce(new Error('You do not have permission to perform this action.'));
  mount();fireEvent.click(screen.getByRole('button',{name:'View proof 1'}));
  expect(await screen.findByRole('alert')).toHaveTextContent('permission');
  expect(mocks.create).not.toHaveBeenCalled();
});
it('cancels a closed preview and ignores its late response', async () => {
  let resolve!: (value:Blob)=>void;
  mocks.getBlob.mockImplementationOnce(()=>new Promise<Blob>(done=>{resolve=done;}));
  const view=mount();fireEvent.click(screen.getByRole('button',{name:'View proof 1'}));
  const signal=mocks.getBlob.mock.calls[0][1] as AbortSignal;
  fireEvent.click(screen.getByRole('button',{name:'Close preview'}));
  expect(signal.aborted).toBe(true);
  resolve(new Blob(['image'],{type:'image/png'}));
  await waitFor(()=>expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  expect(mocks.create).not.toHaveBeenCalled();view.unmount();
});
it('renders PDFs inside the portal instead of starting a download', async () => {
  mocks.getBlob.mockResolvedValueOnce(new Blob(['%PDF-1.7'],{type:'application/pdf'}));
  mount();fireEvent.click(screen.getByRole('button',{name:'View proof 1'}));
  await screen.findByText('PDF rendered inside the portal');
  expect(mocks.download).not.toHaveBeenCalled();expect(mocks.create).not.toHaveBeenCalled();
});
