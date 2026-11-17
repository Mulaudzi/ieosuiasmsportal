import { AdminLayout } from '@/components/layout/AdminLayout';
import { WhatsAppConnections } from '@/components/settings/WhatsAppConnections';

export default function AdminWhatsApp() {
  return <AdminLayout title="WhatsApp setup" subtitle="Platform connection and administrator pilot">
    <div className="mx-auto max-w-4xl space-y-5">
      <section className="space-y-2 rounded-2xl border bg-card p-5 sm:p-6" aria-labelledby="whatsapp-platform-title">
        <h2 id="whatsapp-platform-title" className="text-lg font-semibold">WhatsApp platform connection</h2>
        <p className="text-sm text-muted-foreground">Connect IEOSUIA's WhatsApp Business account here to complete the first setup check through Meta.</p>
        <p className="text-sm text-muted-foreground">Customers will connect their own business numbers from their workspaces when customer signup opens.</p>
      </section>
      <WhatsAppConnections />
    </div>
  </AdminLayout>;
}
