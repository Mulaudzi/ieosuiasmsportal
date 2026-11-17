import { useCallback, useEffect, useRef, useState, type ChangeEvent } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, BookOpen, Building2, Check, CheckCircle2, ChevronRight, CircleHelp, Code2, ExternalLink, ImagePlus, Link2, Loader2, Mail, MessageCircle, MessageSquare, ShieldCheck, UserRound } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { PageLoading } from '@/components/ui/page-loading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { GetStartedChecklist } from '@/components/dashboard/GetStartedChecklist';
import { useAuth } from '@/hooks/useAuth';
import { toast } from '@/hooks/use-toast';
import { api, getProfile, updateProfile, uploadBranding } from '@/lib/api';
import { LoginSecurity } from './LoginSecurity';
import { PasswordSecurityCard } from './PasswordSecurityCard';
import { SmsOptOutSettings } from './SmsOptOutSettings';
import { SmsApiAccess } from './SmsApiAccess';
import { WhatsAppConnections } from './WhatsAppConnections';
import { SettingsNavigation, settingsTitles, settingsView, type SettingsView } from './SettingsNavigation';
import { SettingsIllustration, type SettingsArtwork } from './SettingsIllustration';
import './settings-workspace.css';

const profileSchema = z.object({ name: z.string().min(2, 'Name must be at least 2 characters'), phone: z.string().optional() });
const businessSchema = z.object({ company_name: z.string().min(1, 'Company name is required'), industry: z.string().optional(), address: z.string().optional(), website: z.string().url('Enter a valid website URL').optional().or(z.literal('')) });
type ProfileValues = z.infer<typeof profileSchema>;
type BusinessValues = z.infer<typeof businessSchema>;
interface ProfileDetails { id?: string; name?: string; email?: string; phone?: string; avatar_url?: string; email_verified_at?: string | null }
interface BusinessDetails { company_name?: string; industry?: string; address?: string; website?: string; logo_url?: string }
const readImage = (file: File) => new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(new Error('The image could not be read. Please choose it again.')); reader.readAsDataURL(file); });

export default function SettingsWorkspace() {
  const { user } = useAuth();
  return <SettingsAccountWorkspace key={user?.id ?? 'signed-out'}/>;
}

function SettingsAccountWorkspace() {
  const { user, updateUser, isEmailVerified } = useAuth();
  const [params, setParams] = useSearchParams(); const view = settingsView(params.get('tab'));
  const [profile, setProfile] = useState<ProfileDetails | null>(null), [business, setBusiness] = useState<BusinessDetails | null>(null);
  const [loading, setLoading] = useState(true), [loadError, setLoadError] = useState(''), [saving, setSaving] = useState(''), [editing, setEditing] = useState(false);
  const [avatar, setAvatar] = useState(''), [logo, setLogo] = useState('');
  const avatarInput = useRef<HTMLInputElement>(null), logoInput = useRef<HTMLInputElement>(null), generation = useRef(0);
  const heading = useRef<HTMLHeadingElement>(null), previousView = useRef(view);
  const profileForm = useForm<ProfileValues>({ resolver: zodResolver(profileSchema), defaultValues: { name: '', phone: '' } });
  const businessForm = useForm<BusinessValues>({ resolver: zodResolver(businessSchema), defaultValues: { company_name: '', industry: '', address: '', website: '' } });
  const load = useCallback(async () => {
    if (!user) { setLoading(false); return; }
    const request = ++generation.current; setLoading(true); setLoadError('');
    try {
      const response = await getProfile(); if (!response.success) throw Error('Could not load account details.');
      const data = response.data ?? response; if (request !== generation.current) return;
      const nextProfile = data.user ?? user, nextBusiness = data.account ?? {};
      setProfile(nextProfile); setBusiness(nextBusiness); setAvatar(nextProfile.avatar_url ?? ''); setLogo(nextBusiness.logo_url ?? '');
      profileForm.reset({ name: nextProfile.name ?? '', phone: nextProfile.phone ?? '' });
      businessForm.reset({ company_name: nextBusiness.company_name ?? '', industry: nextBusiness.industry ?? '', address: nextBusiness.address ?? '', website: nextBusiness.website ?? '' });
    } catch { if (request === generation.current) setLoadError('We could not refresh your account details. Please try again.'); }
    finally { if (request === generation.current) setLoading(false); }
  }, [user?.id, profileForm, businessForm]);
  useEffect(() => { setProfile(null); setBusiness(null); setAvatar(''); setLogo(''); setEditing(false); void load(); return () => { generation.current++; }; }, [load]);
  useEffect(() => { if (previousView.current !== view) { heading.current?.focus({ preventScroll: true }); previousView.current = view; } }, [view]);
  const details = profile ?? user;
  const verified = isEmailVerified || !!details?.email_verified_at;
  const hrefFor = (tab: SettingsView) => { const next = new URLSearchParams(params); next.set('tab', tab); return '/settings?' + next.toString(); };
  const select = (tab: SettingsView) => setParams(previous => { const next = new URLSearchParams(previous); next.set('tab', tab); return next; });
  const saveProfile = async (values: ProfileValues) => {
    const request = generation.current; setSaving('profile');
    try {
      const response = await updateProfile(values); if (!response.success) throw Error('Your profile could not be saved. Please try again.');
      if (request !== generation.current) return;
      const updated = response.user ?? response.data?.user ?? { ...details, ...values }; setProfile(updated);
      await updateUser(values); profileForm.reset(values); setEditing(false); toast({ title: 'Profile updated' });
    } catch (error) { toast({ title: 'Profile not saved', description: error instanceof Error ? error.message : 'Please try again.', variant: 'destructive' }); }
    finally { setSaving(''); }
  };
  const saveBusiness = async (values: BusinessValues) => {
    const request = generation.current; setSaving('organization');
    try {
      const response = await updateProfile(values); if (!response.success) throw Error('Your business details could not be saved. Please try again.');
      if (request !== generation.current) return;
      setBusiness(response.account ?? response.data?.account ?? { ...business, ...values }); businessForm.reset(values); toast({ title: 'Business details saved' });
    } catch (error) { toast({ title: 'Business details not saved', description: error instanceof Error ? error.message : 'Please try again.', variant: 'destructive' }); }
    finally { setSaving(''); }
  };
  const uploadImage = async (event: ChangeEvent<HTMLInputElement>, kind: 'avatar' | 'logo') => {
    const request = generation.current;
    const file = event.target.files?.[0]; event.target.value = ''; if (!file) return;
    const maximum = kind === 'avatar' ? 2 : 5;
    if (!file.type.startsWith('image/') || file.size > maximum * 1024 * 1024) { toast({ title: 'Choose a smaller image', description: `Choose an image file up to ${maximum} MB.`, variant: 'destructive' }); return; }
    setSaving(kind);
    try {
      if (kind === 'avatar') {
        const image = await readImage(file); if (request !== generation.current) return;
        const response = await api.post<{ avatar_url?: string }>('/auth/avatar', { avatar: image });
        if (!response.success) throw Error('Your profile photo could not be uploaded.');
        if (request !== generation.current) return;
        const url = response.data?.avatar_url ?? (response.avatar_url as string) ?? image; setAvatar(url); await updateUser({ avatar_url: url });
      } else {
        const body = new FormData(); body.append('logo', file); const response = await uploadBranding(body);
        if (!response.success) throw Error('Your business logo could not be uploaded.');
        const preview = await readImage(file); if (request !== generation.current) return; setLogo(preview);
      }
      toast({ title: kind === 'avatar' ? 'Profile photo updated' : 'Business logo updated' });
    } catch (error) { toast({ title: 'Upload unsuccessful', description: error instanceof Error ? error.message : 'Please try again.', variant: 'destructive' }); }
    finally { setSaving(''); }
  };
  if (user?.role === 'admin' && view === 'whatsapp') return <Navigate to="/guymhan/whatsapp" replace/>;
  const title = settingsTitles[view];
  const needsAccountData = ['overview', 'profile', 'organization'].includes(view);
  return <DashboardLayout title="Settings" subtitle="Your account, organized.">
    <div className="settings-workspace">
      <SettingsNavigation view={view} hrefFor={hrefFor} onSelect={select}/>
      <div className="settings-content" id="settings-content">
        <div className="settings-section-heading">
          <p className="settings-breadcrumb"><Link to={hrefFor('overview')}>Settings</Link><ChevronRight size={12} aria-hidden="true"/>{view === 'api' || view === 'whatsapp' ? <><Link to={hrefFor('integrations')}>Connected apps</Link><ChevronRight size={12} aria-hidden="true"/></> : null}<span>{title[0]}</span></p>
          <div className="settings-heading-row"><div className="settings-heading-copy"><h2 ref={heading} tabIndex={-1}>{title[0]}</h2><p>{title[1]}</p></div><SettingsIllustration kind={(['api','whatsapp'].includes(view) ? 'integrations' : view === 'get-started' ? 'help' : view) as SettingsArtwork}/></div>
          {(view === 'api' || view === 'whatsapp') && <Link className="settings-back" to={hrefFor('integrations')}><ArrowLeft size={14} aria-hidden="true"/>Back to connected apps</Link>}
        </div>
        {loadError && <div className="settings-load-error" role="alert"><p>{loadError}</p><Button variant="outline" size="sm" onClick={() => void load()}>Retry account details</Button></div>}
        {loading && needsAccountData ? <PageLoading variant="form" label="Loading account settings"/> : <>
          {view === 'overview' && <>
            <section className="settings-account-card" aria-label="Account summary"><div className="settings-avatar">{avatar ? <img src={avatar} alt="Your profile"/> : <UserRound size={26} aria-hidden="true"/>}</div><div><p className="settings-eyebrow">SIGNED IN AS</p><h3>{details?.name || 'Your account'}</h3><p>{details?.email || 'Account email unavailable'}</p></div><span className="settings-account-badge">{verified ? <><CheckCircle2 size={14} aria-hidden="true"/>Email verified</> : <><Mail size={14} aria-hidden="true"/>Email not verified</>}</span></section>
            <div className="settings-overview-grid">{[
              { view: 'profile' as const, icon: UserRound, title: 'Personal details', text: 'Update your name, phone number and profile photo.', action: 'Edit my profile', tone: 'blue' },
              { view: 'security' as const, icon: ShieldCheck, title: 'Sign in with confidence', text: 'Manage email verification and your IEOSUIA password.', action: 'Review security', tone: 'green' },
              { view: 'compliance' as const, icon: MessageSquare, title: 'Your SMS preferences', text: 'Set the opt-out wording available in your message composer.', action: 'Manage SMS preferences', tone: 'purple' },
              { view: 'integrations' as const, icon: Link2, title: 'Connect your software', text: 'Find API access and WhatsApp setup in one place.', action: 'View connected apps', tone: 'amber' },
            ].map(card => <Link key={card.view} to={hrefFor(card.view)} className="settings-overview-card settings-illustrated-card" data-tone={card.tone}><div className="settings-card-visual"><SettingsIllustration kind={card.view as SettingsArtwork}/></div><h3>{card.title}</h3><p>{card.text}</p><span className="settings-card-action">{card.action}<ArrowRight size={15} aria-hidden="true"/></span></Link>)}</div>
            <div className="settings-overview-bottom"><Link to={hrefFor('organization')}><Building2 size={20} aria-hidden="true"/><span><strong>{business?.company_name || 'Business details'}</strong><small>Organization information & branding</small></span><ChevronRight size={16} aria-hidden="true"/></Link><Link to={hrefFor('get-started')}><CircleHelp size={20} aria-hidden="true"/><span><strong>A little help getting started?</strong><small>Quick pointers, guides & support</small></span><ChevronRight size={16} aria-hidden="true"/></Link></div>
          </>}
          {view === 'profile' && <>
            <section className="settings-card settings-photo-card"><div className="settings-avatar settings-avatar-large">{avatar ? <img src={avatar} alt="Your profile photo"/> : <UserRound size={32} aria-hidden="true"/>}</div><div><h3>Profile photo</h3><p>Make your account easier to recognize.</p><input ref={avatarInput} type="file" accept="image/*" aria-label="Choose profile photo" className="sr-only" onChange={event => void uploadImage(event, 'avatar')}/><Button variant="outline" size="sm" disabled={!!saving} onClick={() => avatarInput.current?.click()}><ImagePlus size={15} className="mr-2" aria-hidden="true"/>{saving === 'avatar' ? 'Uploading…' : 'Change photo'}</Button><small>Image file, up to 2 MB.</small></div></section>
            <section className="settings-card"><div className="settings-card-heading"><div><h3>Personal information</h3><p>Your name, account email and contact number.</p></div>{!editing && <Button variant="outline" disabled={!!loadError} onClick={() => setEditing(true)}>Edit profile</Button>}</div>
              {editing ? <form onSubmit={profileForm.handleSubmit(saveProfile)} aria-label="Edit profile"><div className="settings-form-grid"><div><Label htmlFor="settings-name">Full name</Label><Input id="settings-name" autoComplete="name" {...profileForm.register('name')}/>{profileForm.formState.errors.name && <p role="alert" className="settings-field-error">{profileForm.formState.errors.name.message}</p>}</div><div><Label htmlFor="settings-phone">Phone number</Label><Input id="settings-phone" type="tel" autoComplete="tel" {...profileForm.register('phone')}/></div></div><div className="settings-readonly-email"><Mail size={16} aria-hidden="true"/><div><span>Account email</span><p>{details?.email || 'Not available'}</p><small>Your sign-in email is not changed here.</small></div></div><div className="settings-save-actions"><Button type="button" variant="outline" disabled={!!saving} onClick={() => { profileForm.reset({ name: details?.name ?? '', phone: details?.phone ?? '' }); setEditing(false); }}>Cancel</Button><Button disabled={!!saving || !!loadError}>{saving === 'profile' ? <Loader2 size={15} className="mr-2 animate-spin" aria-hidden="true"/> : <Check size={15} className="mr-2" aria-hidden="true"/>}Save profile</Button></div></form>
              : <dl className="settings-detail-grid"><div><dt>Full name</dt><dd>{details?.name || 'Not set'}</dd></div><div><dt>Phone number</dt><dd>{details?.phone || 'Not set'}</dd></div><div className="settings-detail-wide"><dt>Account email</dt><dd>{details?.email || 'Not available'}{verified && <span className="settings-verified-inline"><CheckCircle2 size={13} aria-hidden="true"/>Verified</span>}</dd></div></dl>}
            </section>
          </>}
          {view === 'organization' && <form className="settings-card" aria-label="Business details" onSubmit={businessForm.handleSubmit(saveBusiness)}>
            <div className="settings-brand-row"><div className="settings-business-logo">{logo ? <img src={logo} alt="Business logo"/> : <Building2 size={28} aria-hidden="true"/>}</div><div><h3>Business logo</h3><p>Use an image that represents your organization.</p><input ref={logoInput} type="file" accept="image/*" className="sr-only" aria-label="Choose business logo" onChange={event => void uploadImage(event, 'logo')}/><Button type="button" variant="outline" size="sm" disabled={!!saving} onClick={() => logoInput.current?.click()}><ImagePlus size={15} className="mr-2" aria-hidden="true"/>{saving === 'logo' ? 'Uploading…' : 'Upload logo'}</Button><small>Image file, up to 5 MB. A wide logo works best.</small></div></div>
            <div className="settings-form-grid"><div><Label htmlFor="settings-company">Organization name</Label><Input id="settings-company" autoComplete="organization" {...businessForm.register('company_name')}/>{businessForm.formState.errors.company_name && <p role="alert" className="settings-field-error">{businessForm.formState.errors.company_name.message}</p>}</div><div><Label htmlFor="settings-industry">Industry <span className="settings-optional">optional</span></Label><Input id="settings-industry" {...businessForm.register('industry')}/></div><div className="settings-detail-wide"><Label htmlFor="settings-website">Website <span className="settings-optional">optional</span></Label><Input id="settings-website" type="url" autoComplete="url" placeholder="https://your-website.com" {...businessForm.register('website')}/>{businessForm.formState.errors.website && <p role="alert" className="settings-field-error">{businessForm.formState.errors.website.message}</p>}</div><div className="settings-detail-wide"><Label htmlFor="settings-address">Address <span className="settings-optional">optional</span></Label><Textarea id="settings-address" autoComplete="street-address" rows={3} {...businessForm.register('address')}/></div></div>
            <div className="settings-save-actions"><span>{businessForm.formState.isDirty ? 'You have unsaved changes' : 'Changes apply to this account’s business details.'}</span><Button disabled={!!saving || !!loadError}>{saving === 'organization' ? <Loader2 size={15} className="mr-2 animate-spin" aria-hidden="true"/> : <Check size={15} className="mr-2" aria-hidden="true"/>}Save business details</Button></div>
          </form>}
        </>}
        {view === 'security' && <div className="settings-section-stack"><section className="settings-security-map" aria-label="How your sign-in controls differ"><div><span className="settings-security-map-icon"><Mail size={22} aria-hidden="true"/></span><div><strong>Email verification</strong><p>Specific to the SMS Portal</p><span>THIS APP ONLY</span></div></div><div><span className="settings-security-map-icon"><ShieldCheck size={22} aria-hidden="true"/></span><div><strong>IEOSUIA password</strong><p>Shared by apps that use the same sign-in</p><span>YOUR IEOSUIA ACCOUNT</span></div></div></section><LoginSecurity/><PasswordSecurityCard/></div>}
        {view === 'compliance' && <div className="settings-section-stack"><section className="settings-card"><div className="settings-card-heading"><div><h3>Default opt-out text</h3><p>Set the wording, then choose whether to include it when composing a campaign.</p></div><MessageSquare size={22} aria-hidden="true"/></div><SmsOptOutSettings/></section><aside className="settings-context-note"><ShieldCheck size={18} aria-hidden="true"/><div><strong>Wording is not a compliance switch.</strong><p>Changing this text does not enable reply processing or certify legal compliance. Make sure your sender can receive replies and that opt-out requests are handled. Existing campaigns are unchanged.</p><Link to="/popia-compliance">Read privacy & consent guidance<ExternalLink size={13} aria-hidden="true"/></Link></div></aside></div>}
        {view === 'integrations' && <div className="settings-section-stack"><div className="settings-connection-grid"><Link className="settings-overview-card settings-illustrated-connection" to={hrefFor('api')}><SettingsIllustration kind="integrations"/><span className="settings-type-label">FOR YOUR SOFTWARE</span><h3>SMS API</h3><p>Connect Blu-T, your website or another application. Manage its keys and credit allowance here.</p><span className="settings-card-action">Manage API connections<ArrowRight size={15} aria-hidden="true"/></span></Link><Link className="settings-overview-card settings-illustrated-connection" to={user?.role === 'admin' ? '/guymhan/whatsapp' : hrefFor('whatsapp')}><SettingsIllustration kind="compliance"/><span className="settings-type-label">FOR YOUR BUSINESS NUMBER</span><h3>WhatsApp Business</h3><p>Link a business number through Meta. Connection setup is separate from sending campaigns.</p><span className="settings-card-action">Manage WhatsApp connection<ArrowRight size={15} aria-hidden="true"/></span></Link></div><div className="settings-context-note"><Link2 size={18} aria-hidden="true"/><p>Opening these settings does not create a key, link a number or send a message. You confirm each action separately.</p></div></div>}
        {view === 'api' && <SmsApiAccess/>}
        {view === 'whatsapp' && <WhatsAppConnections/>}
        {view === 'get-started' && <div className="settings-section-stack"><section className="settings-card"><h3>Your first steps</h3><p className="settings-muted">Progress below comes from your account’s actual activity.</p><div className="settings-checklist"><GetStartedChecklist/></div></section><div className="settings-help-links">{[{ to: '/documentation', icon: BookOpen, label: 'Portal guide', text: 'Contacts, credits and campaigns.' }, { to: '/developers', icon: Code2, label: 'API developer guide', text: 'Connect your application safely.' }, { to: '/support', icon: CircleHelp, label: 'Contact support', text: 'Help when you need a person.' }].map(item => <Link key={item.to} to={item.to}><item.icon size={20} aria-hidden="true"/><span><strong>{item.label}</strong><small>{item.text}</small></span><ChevronRight size={16} aria-hidden="true"/></Link>)}</div></div>}
      </div>
    </div>
  </DashboardLayout>;
}
