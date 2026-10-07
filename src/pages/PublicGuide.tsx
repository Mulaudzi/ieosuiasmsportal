import { Link, useLocation } from 'react-router-dom';
import { Footer } from '@/components/layout/Footer';
import { SignedInPublicHeader } from '@/components/layout/PublicChrome';
import guides from '@/seo/guides.json';
import faqs from '@/seo/faq.json';
import '@/seo/public-seo.css';
export default function PublicGuide() {
  const { pathname } = useLocation();
  const guide = guides[pathname.slice(1) as keyof typeof guides];
  const faq = pathname === '/faq';
  return <><SignedInPublicHeader/><main className="public-guide"><h1>{faq ? 'Questions about bulk SMS and the SMS Portal' : guide.heading}</h1><p className="guide-lead">{faq ? 'Practical answers for South African businesses preparing SMS campaigns, reviewing credit costs and understanding delivery reports. These answers describe the current SMS release.' : guide.intro}</p>
    {faq ? faqs.map(item => <details key={item.question}><summary>{item.question}</summary><p>{item.answer}</p></details>) : guide.sections.map(section => <section key={section.heading}><h2>{section.heading}</h2>{section.paragraphs.map(text => <p key={text}>{text}</p>)}</section>)}
    <nav aria-label="Related guides" className="guide-links">{(faq ? [['/pricing','Check SMS pricing'],['/documentation','Read the setup guide'],['/support','Contact support']] : guide.links).map(([href,label]) => <Link key={href} to={href}>{label}</Link>)}</nav><div className="guide-links"><Link to="/register">Start Messaging</Link><Link to="/features">Explore the SMS platform</Link></div>
  </main><Footer variant="simple"/></>;
}
