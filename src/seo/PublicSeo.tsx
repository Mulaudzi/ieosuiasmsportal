import socialLinks from '@/components/layout/official-social-links.json';
import { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import pages from './public-pages.json';
import faqs from './faq.json';
const origin = 'https://sms.ieosuia.com';

export function PublicSeo() {
  const { pathname } = useLocation();
  const page = pages.find(item => item.path === (pathname.replace(/\/$/, '') || '/'));
  useEffect(() => {
    const meta = (key: string, value: string, property = false) => {
      const attr = property ? 'property' : 'name';
      let element = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`);
      if (!element) { element = document.createElement('meta'); element.setAttribute(attr, key); document.head.appendChild(element); }
      element.content = value;
    };
    document.title = page?.title || 'Account | IEOSUIA SMS Portal';
    meta('robots', page ? 'index,follow,max-image-preview:large' : 'noindex,nofollow');
    meta('description', page?.description || 'Private account area of IEOSUIA SMS Portal.');
    document.head.querySelectorAll('link[rel="canonical"],script[data-public-schema],meta[name^="twitter:"]').forEach(el => el.remove());
    if (!page) {
      document.head.querySelectorAll('meta[property^="og:"],meta[name^="twitter:"]').forEach(el => el.remove());
      return;
    }
    const url = origin + page.path;
    const canonical = document.createElement('link'); canonical.rel = 'canonical'; canonical.href = url; document.head.appendChild(canonical);
    for (const [name, value] of Object.entries({ title: page.title, description: page.description, url, type: 'website', site_name: 'IEOSUIA SMS Portal', image: origin + '/social-preview-communications.png' })) meta('og:' + name, value, true);
    const graph: Record<string, unknown>[] = [
      { '@type': 'Organization', '@id': origin + '/#organization', name: 'IEOSUIA', url: 'https://ieosuia.com', logo: origin + '/branding/ieosuia-sms-portal-light.png', sameAs: socialLinks.map(link => link.url) },
      { '@type': 'WebSite', '@id': origin + '/#website', name: 'IEOSUIA SMS Portal', url: origin + '/', publisher: { '@id': origin + '/#organization' } },
      { '@type': 'WebPage', '@id': url + '#webpage', url, name: page.title, description: page.description, inLanguage: 'en-ZA', isPartOf: { '@id': origin + '/#website' }, ...(page.path !== '/' ? { breadcrumb: { '@id': url + '#breadcrumb' } } : {}) },
    ];
    if (page.path !== '/') graph.push({ '@type': 'BreadcrumbList', '@id': url + '#breadcrumb', itemListElement: [{ '@type': 'ListItem', position: 1, name: 'Home', item: origin + '/' }, { '@type': 'ListItem', position: 2, name: page.name, item: url }] });
    if (page.path === '/') graph.push({ '@type': 'SoftwareApplication', '@id': origin + '/#application', name: 'IEOSUIA SMS Portal', url, applicationCategory: 'BusinessApplication', operatingSystem: 'Web browser', description: page.description, publisher: { '@id': origin + '/#organization' }, softwareHelp: { '@type': 'WebPage', url: origin + '/documentation' }, featureList: ['SMS campaigns', 'Contact groups', 'SMS templates', 'Campaign scheduling', 'Delivery reports', 'Prepaid SMS credits'] });
    if (page.path === '/faq') graph.push({ '@type': 'FAQPage', '@id': url + '#faq', mainEntity: faqs.map(faq => ({ '@type': 'Question', name: faq.question, acceptedAnswer: { '@type': 'Answer', text: faq.answer } })) });
    const script = document.createElement('script'); script.type = 'application/ld+json'; script.dataset.publicSchema = 'true'; script.textContent = JSON.stringify({ '@context': 'https://schema.org', '@graph': graph }); document.head.appendChild(script);
  }, [page]);
  return page && page.path !== '/' ? <nav aria-label="Breadcrumb" className="public-breadcrumb"><Link to="/">Home</Link><span aria-hidden="true">/</span><span aria-current="page">{page.name}</span></nav> : null;
}
