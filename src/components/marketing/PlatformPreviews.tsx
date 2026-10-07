import { BarChart3, CalendarClock, Check, ChevronDown, ChevronRight, FileText, HelpCircle, Search, Send, Settings, Users } from "lucide-react";
import { Logo } from "@/components/layout/Logo";
import "./CampaignHero.css";

// Illustrative data only. Never connected to customer records or provider metrics.
export const deliveryDemo = { total: 2480, delivered: 2220, pending: 180, failed: 80 };

export function CampaignPreview() {
  return <figure className="campaign-window" aria-label="Demo SMS Portal workspace, not live customer data">
    <div className="campaign-window-frame">
      <aside className="campaign-window-sidebar" aria-label="Illustrative product areas">
        <Logo variant="navy" linkTo="" size="sm" />
        <div className="campaign-window-items">{[
          { icon: Send, label: 'Campaigns' }, { icon: Users, label: 'Contacts' },
          { icon: FileText, label: 'Templates' }, { icon: BarChart3, label: 'Reports' },
        ].map(({ icon: Icon, label }, index) => <div className={index === 0 ? 'is-current' : ''} key={label}><Icon size={17}/><span>{label}</span></div>)}</div>
        <div className="campaign-window-support"><div><Settings size={17}/> Settings</div><div><HelpCircle size={17}/> Help</div></div>
      </aside>
      <div className="campaign-window-main">
        <div className="campaign-window-toolbar"><span className="campaign-window-demo">DEMO WORKSPACE</span><span className="campaign-window-search" aria-hidden="true"><Search size={13}/> Search campaigns, contacts...</span><span className="campaign-window-avatar" aria-hidden="true">T</span></div>
        <div className="campaign-window-title"><div><h2>Campaign overview</h2><p>Create, send and track your SMS campaigns.</p></div><span>SMS</span></div>
        <div className="campaign-window-card">
          <div className="campaign-window-label"><span>UPCOMING CAMPAIGN</span><b><i/> Scheduled</b></div>
          <h3>October Customer Update</h3>
          <div className="campaign-window-meta"><span><Users size={14}/> 2,480 recipients</span><span><CalendarClock size={14}/> Scheduled at 14:00</span></div>
          <div className="campaign-window-message"><span>MESSAGE PREVIEW</span><p>Hi Thandi, our October collection arrives on Friday. Visit us this weekend to see what's new.</p><small>Reply STOP to unsubscribe</small></div>
          <div className="campaign-window-confirm"><Check size={17}/><span>Audience selected</span><small>1 segment per recipient</small><ChevronRight size={14}/></div>
        </div>
        <div className="campaign-window-snapshot"><h3>Delivery snapshot</h3><span>Previous campaign <ChevronDown size={12}/></span></div>
        <div className="campaign-window-metrics">{[
          { label: 'Delivered', count: deliveryDemo.delivered, color: 'delivered' },
          { label: 'Pending', count: deliveryDemo.pending, color: 'pending' },
          { label: 'Failed', count: deliveryDemo.failed, color: 'failed' },
        ].map(item => <div className={`campaign-window-metric ${item.color}`} key={item.label}><span>{item.label}</span><div><strong>{item.count.toLocaleString('en-US')}</strong><small>{(item.count / deliveryDemo.total * 100).toFixed(1)}%</small></div><div className="campaign-window-track" aria-hidden="true"><i style={{width:`${item.count / deliveryDemo.total * 100}%`}}/></div></div>)}</div>
      </div>
    </div>
    <figcaption>Product preview. All names, schedules and delivery figures are illustrative.</figcaption>
  </figure>;
}

export function AudiencePreview() {
  return <figure className="ops-audience-preview" aria-label="Demo contact groups and opt-out handling">
    <figcaption className="ops-preview-top"><span>AUDIENCE DIRECTORY</span><span className="ops-demo-label">DEMO DATA</span></figcaption>
    <div className="ops-audience-groups"><span className="is-selected">Retail customers <b>320</b></span><span>Event guests <b>124</b></span></div>
    <div className="ops-contact-table"><div className="ops-contact-head"><span>Contact</span><span>SMS permission</span></div>{[
      ['TM','Thandi Mokoena','Active'],['SN','Sibusiso Ndlovu','Active'],['LK','Lerato Khumalo','Opted out'],
    ].map(([initials,name,status])=><div className="ops-contact-row" key={name}><span className="ops-contact-person"><i>{initials}</i><span>{name}<small>Retail customers</small></span></span><span className={status==='Active'?'ops-permission-active':'ops-permission-off'}>{status}</span></div>)}</div>
    <div className="ops-audience-summary"><span><strong>320</strong>contacts in group</span><span><strong>8</strong>opted out</span><span><strong>312</strong>sendable</span></div>
    <div className="ops-preview-bottom"><Check size={13}/> Opted-out numbers are excluded from the campaign.</div>
  </figure>;
}

export function DeliveryPreview() {
  const rate = (deliveryDemo.delivered / deliveryDemo.total * 100).toFixed(1);
  return <figure className="ops-delivery-preview" aria-label="Demo delivery report for 2480 messages">
    <figcaption className="ops-preview-top"><span>DELIVERY REPORT</span><span className="ops-demo-label">DEMO DATA</span></figcaption>
    <div className="ops-report-summary"><div><span>Messages sent</span><strong>2,480</strong></div><div><span>Confirmed delivery rate</span><strong>{rate}<small>%</small></strong></div></div>
    <div className="ops-report-rows">{[
      {label:'Delivered',count:deliveryDemo.delivered,style:'delivered',detail:'Delivery confirmed'},
      {label:'Pending',count:deliveryDemo.pending,style:'pending',detail:'Waiting for delivery confirmation'},
      {label:'Failed',count:deliveryDemo.failed,style:'failed',detail:'Failure reported'},
    ].map(row=><div key={row.label}><div><span><i className={`ops-status-dot ops-${row.style}-bg`}/>{row.label}</span><strong>{row.count.toLocaleString('en-ZA')}</strong></div><div className="ops-report-track" aria-hidden="true"><i className={`ops-${row.style}-bg`} style={{width:`${row.count/deliveryDemo.total*100}%`}}/></div><small>{row.detail}</small></div>)}</div>
    <div className="ops-report-note">Sent is not the same as delivered. Confirmation comes from the SMS provider.</div>
  </figure>;
}
