import { SocialLinks } from "@/components/layout/SocialLinks";
import { WhatsAppButton } from "@/components/layout/WhatsAppButton";
import { EcosystemSection } from "@/components/marketing/EcosystemSection";
import { useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { Logo } from "@/components/layout/Logo";
import { ArrowRight, ArrowUpRight, BarChart3, CalendarClock, Check, FileText, Layers, Loader2, Mail, MessageSquare, Users, Wallet } from "lucide-react";
import { AudiencePreview, CampaignPreview, DeliveryPreview } from "@/components/marketing/PlatformPreviews";
import "./Landing.css";

const modules = [
  { icon: MessageSquare, name: "Campaigns", text: "Create, review and manage every send.", href: "#workflow" },
  { icon: Users, name: "Contacts & groups", text: "Build audiences from your saved contact lists.", href: "#audiences" },
  { icon: FileText, name: "Templates", text: "Save reusable messages and personalise them.", href: "#workflow" },
  { icon: CalendarClock, name: "Scheduling", text: "Send now or choose a time for later.", href: "#workflow" },
  { icon: BarChart3, name: "Delivery reports", text: "Follow recipient statuses and campaign results.", href: "#reporting" },
  { icon: Wallet, name: "Credits & usage", text: "Review your balance and campaign requirements.", href: "#pricing" },
];
const steps = [
  ["Build your audience", "Import contacts from Excel or CSV, or select an existing group."],
  ["Compose your message", "Write your SMS or start with a saved template. Review personalisation and your opt-out footer."],
  ["Schedule or send", "Check recipients and credits, then send immediately or schedule a time."],
  ["Track delivery", "Monitor delivered, pending and failed messages in your campaign reports."],
];
const useCases = [
  ["01", "Retail", "Share a new collection or promotion with your customer group."],
  ["02", "Schools", "Send timetable changes and reminders to parent contact lists."],
  ["03", "Churches & communities", "Keep members informed about gatherings and community notices."],
  ["04", "Events", "Schedule reminders and send last-minute venue updates to guests."],
  ["05", "Internal teams", "Send shift reminders or operational notices to the right team."],
  ["06", "Customer notifications", "Send service reminders, order updates and collection notices."],
];
const faqs = [
  ["What can I manage in the portal?", "SMS campaigns, contacts, groups, reusable templates, scheduling, delivery reports and prepaid credits. Email campaigns are coming soon and are not available for sending yet."],
  ["Can I import my existing contacts?", "Yes. Import an Excel (.xlsx) or CSV file, choose your worksheet, map the columns and assign contacts to an existing group. The import flow identifies duplicates and reports the result."],
  ["How are SMS credits calculated?", "One credit covers one billable SMS segment at R0.35. Longer messages, Unicode characters, personalisation and opt-out text can increase the segment count. Review the calculated requirement before sending."],
  ["Is there a subscription or a minimum purchase?", "Messaging is prepaid, with no monthly subscription. The minimum purchase is R10. At R0.35 per segment, this covers 28 whole credits, with the remaining value retained in your wallet."],
  ["Does sent mean delivered?", "No. Sent means the SMS provider accepted the message. Delivered means delivery was confirmed. Messages without confirmation remain pending, including those whose delivery report is unavailable."],
  ["How are opt-outs handled?", "Opted-out numbers are excluded from future campaigns. You can review opt-outs in your contacts and choose whether to include the opt-out footer when preparing a message."],
];

export default function Landing() {
  const { isAuthenticated, isLoading } = useAuth();
  const navigate = useNavigate();
  useEffect(() => { if (!isLoading && isAuthenticated) navigate("/dashboard", { replace: true }); }, [isLoading, isAuthenticated, navigate]);
  if (isLoading) return <div className="min-h-screen flex items-center justify-center" role="status"><Loader2 className="h-7 w-7 animate-spin mr-3"/>Loading...</div>;
  return <div className="sms-home">
    <a href="#main" className="home-skip">Skip to content</a>
    <main id="main">
      <section className="home-hero">
        <div className="ops-wrap ops-hero-grid">
          <div className="ops-hero-copy"><p className="ops-eyebrow"><span className="ops-status-dot"/> THE IEOSUIA COMMUNICATIONS WORKSPACE</p><h1>Build audiences.<br/>Send campaigns.<br/><em>Track delivery.</em></h1><p className="ops-lead">Manage contacts, SMS campaigns, schedules and delivery from one place. Keep every message organised, from the first recipient to the final report.</p><div className="ops-actions"><Link to="/register" className="ops-button">Start Messaging <ArrowRight size={18}/></Link><a href="#platform" className="ops-text-link">Explore the Platform <ArrowUpRight size={17}/></a></div><div className="ops-hero-facts"><span><Check size={14}/> Prepaid SMS</span><span><Check size={14}/> Scheduled campaigns</span><span><Check size={14}/> Recipient-level reports</span></div></div>
          <CampaignPreview/>
        </div>
        <div className="ops-wrap ops-hero-baseline"><span>BUILT AROUND YOUR MESSAGING OPERATION</span><div><span>Campaigns</span><i/><span>Audiences</span><i/><span>Delivery</span><i/><span>Insights</span></div></div>
      </section>

      <section id="platform" className="ops-section ops-wrap ops-platform">
        <div className="ops-section-intro"><p className="ops-kicker">01 / THE PLATFORM</p><h2>Your messaging operation,<br/>in one place.</h2><p>A South African business SMS workspace for the people you need to reach. Manage bulk SMS campaign preparation, contact groups and delivery reporting in one place.</p><div className="ops-platform-note"><Layers size={21}/><span>Built for bulk SMS.<br/><strong>Designed around the whole campaign.</strong></span></div></div>
        <div className="ops-module-directory">{modules.map(({icon:Icon,name,text,href})=><a href={href} className="ops-module" key={name}><Icon size={21} strokeWidth={1.6}/><span><h3>{name}</h3><p>{text}</p></span><ArrowUpRight size={16}/></a>)}</div>
      </section>

      <section id="features" className="ops-workflow-section"><div id="workflow" className="ops-wrap ops-section">
        <div className="ops-section-heading"><div><p className="ops-kicker">02 / CAMPAIGN WORKFLOW</p><h2>A clear path from audience to outcome.</h2></div><p>Prepare the details once.<br/>Follow the campaign from start to finish.</p></div>
        <ol className="ops-workflow">{steps.map(([title,text],i)=><li key={title}><span className="ops-step-number">0{i+1}</span><h3>{title}</h3><p>{text}</p></li>)}</ol>
      </div></section>

      <section id="audiences" className="ops-wrap ops-section ops-audience-section"><AudiencePreview/><div className="ops-section-intro"><p className="ops-kicker">03 / AUDIENCES & CONTACTS</p><h2>A contact list is just the beginning.</h2><p>Organise people into groups that make sense for your business. Choose who receives each campaign without rebuilding your contact list.</p><ul className="ops-checklist"><li><Check/>Import Excel and CSV contact files</li><li><Check/>Select groups or individual recipients</li><li><Check/>Identify duplicate numbers before sending</li><li><Check/>Keep opt-outs excluded from future campaigns</li></ul><a href="#workflow" className="ops-text-link">See the campaign workflow <ArrowRight size={17}/></a></div></section>

      <section id="reporting" className="ops-reporting"><div className="ops-wrap ops-section ops-reporting-grid"><div className="ops-section-intro"><p className="ops-kicker">04 / DELIVERY & REPORTING</p><h2>Know what was sent.<br/><em>See what was delivered.</em></h2><p>Go beyond a campaign total. Review recipient-level delivery states, revisit campaign history and export your reports.</p><div className="ops-report-capabilities"><span>Sent, delivered, pending & failed</span><span>Delivery rate & campaign history</span><span>PDF, CSV & Excel exports</span></div><p className="ops-report-caveat">Delivery is shown as confirmed only when a provider report is received.</p></div><DeliveryPreview/></div></section>

      <section id="use-cases" className="ops-wrap ops-section"><div className="ops-section-heading"><div><p className="ops-kicker">05 / EVERYDAY OPERATIONS</p><h2>The right update.<br/>The right contact group.</h2></div><p>Use SMS for the practical messages<br/>your people need to receive.</p></div><div className="ops-use-cases">{useCases.map(([number,title,text])=><article key={title}><span>{number}</span><h3>{title}</h3><p>{text}</p></article>)}</div></section>

      <section id="pricing" className="ops-prepaid"><div className="ops-wrap ops-prepaid-grid"><div><p className="ops-kicker">CREDITS, WITHOUT THE COMPLICATION</p><h2>Simple prepaid messaging.</h2><p>Buy the credits you need. Review campaign costs before sending. No monthly subscription.</p></div><div className="ops-rate"><strong>R0.35</strong><span>per billable SMS segment</span></div><div className="ops-minimum"><strong>Start with R10</strong><p>One credit per segment. Longer or Unicode messages can require more credits.</p><Link to="/register" className="ops-text-link">Get started <ArrowRight size={16}/></Link></div></div></section>

      <section className="ops-wrap ops-section ops-channels"><div className="ops-section-intro"><p className="ops-kicker">ROOM TO GROW</p><h2>SMS today.<br/>More communication channels next.</h2><p>Your SMS workspace is ready. Email campaigns will follow as the platform develops.</p></div><div className="ops-channel-list"><div><MessageSquare size={24}/><span><h3>SMS campaigns</h3><p>Contacts, scheduling and delivery reporting.</p></span><b className="ops-available">Available now</b></div><div><Mail size={24}/><span><h3>Email campaigns</h3><p>Not available for sending in this release.</p></span><b className="ops-coming">Coming Soon</b></div></div></section>

      <section id="faq" className="ops-faq-section"><div className="ops-wrap ops-section ops-faq"><div className="ops-section-intro"><p className="ops-kicker">BEFORE YOU START</p><h2>A few useful answers.</h2><p>Need help with your setup?<br/><Link to="/contact" className="ops-text-link">Talk to our team <ArrowUpRight size={16}/></Link></p></div><div className="ops-faq-list">{faqs.map(([question,answer])=><details key={question}><summary>{question}<span aria-hidden="true">+</span></summary><p>{answer}</p></details>)}</div></div></section>

      <section className="ops-wrap ops-final"><div><p className="ops-kicker">YOUR NEXT CAMPAIGN STARTS HERE</p><h2>Bring your messaging<br/>under one roof.</h2></div><div><Link to="/register" className="ops-button">Start Messaging <ArrowRight size={18}/></Link><p>Already have an account? <Link to="/login">Sign In</Link></p></div></section>
      <EcosystemSection />
    </main>
    <footer className="ops-footer"><div className="ops-wrap"><div className="ops-footer-grid"><div className="ops-footer-brand"><Logo variant="navy" size="lg"/><p>The IEOSUIA workspace for<br/>audiences, campaigns and delivery.</p><a href="tel:+27638082493">+27 63 808 2493</a><SocialLinks className="mt-3" /></div><nav aria-label="Product links"><h2>Product</h2><Link to="/features">SMS platform features</Link><Link to="/use-cases">Business SMS use cases</Link><Link to="/contact-management">Contact management</Link><Link to="/scheduled-sms">Scheduled SMS</Link><Link to="/delivery-reports">Delivery reports</Link><Link to="/pricing">SMS credit pricing</Link><Link to="/faq">SMS questions</Link></nav><nav aria-label="Support links"><h2>Support</h2><Link to="/support">Help centre</Link><Link to="/documentation">Documentation</Link><Link to="/contact">Contact us</Link></nav><nav aria-label="Company links"><h2>Company</h2><a href="https://ieosuia.com" target="_blank" rel="noopener noreferrer">IEOSUIA <ArrowUpRight size={12}/></a><Link to="/careers">Careers</Link></nav></div><div className="ops-footer-bottom"><span>© {new Date().getFullYear()} IEOSUIA. All rights reserved.</span><nav aria-label="Legal links"><Link to="/privacy-policy">Privacy</Link><Link to="/terms-of-service">Terms</Link><Link to="/popia-compliance">POPIA</Link><Link to="/cookie-policy">Cookies</Link></nav></div></div></footer>
    <WhatsAppButton />
  </div>;
}
