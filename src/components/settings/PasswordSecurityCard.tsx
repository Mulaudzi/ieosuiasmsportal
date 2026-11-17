import { ExternalLink, KeyRound, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function PasswordSecurityCard() {
  return <section aria-labelledby="password-security-title" className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
    <div className="flex items-start gap-3"><span className="rounded-xl bg-primary/10 p-3 text-primary"><KeyRound className="h-5 w-5" aria-hidden="true"/></span><div>
      <h3 id="password-security-title" className="text-lg font-semibold">Your IEOSUIA password</h3>
      <p className="mt-1 text-sm text-muted-foreground">One shared password for your IEOSUIA sign-in.</p>
    </div></div>
    <p className="mt-5 text-sm leading-relaxed text-muted-foreground">Need a new password? Open the secure IEOSUIA sign-in page and enter your account email. We’ll email you a link to choose a new password.</p>
    <div className="my-5 rounded-xl border bg-muted/30 p-4 text-sm leading-relaxed">
      <p className="font-medium">What changes?</p>
      <p className="mt-1 text-muted-foreground">Your shared password changes for the IEOSUIA apps that use Central Auth. You may need to sign in again.</p>
      <p className="mt-3 flex items-start gap-2"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true"/><span>Your contacts, campaigns and credits are not deleted or reset. Your SMS Portal extra sign-in setting stays as you chose it.</span></p>
    </div>
    <Button asChild className="w-full sm:w-auto"><a href="https://auth.ieosuia.com/forgot-password">Change IEOSUIA password<ExternalLink className="ml-2 h-4 w-4" aria-hidden="true"/></a></Button>
    <p className="mt-3 text-xs text-muted-foreground">Account security help: <a className="text-primary underline" href="mailto:security@ieosuia.com">security@ieosuia.com</a></p>
  </section>;
}
