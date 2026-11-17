import { useId, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

export function FinancePasswordField({ value, onChange, disabled = false }: { value: string; onChange: (value: string) => void; disabled?: boolean }) {
  const id = useId(); const [visible, setVisible] = useState(false);
  return <div className="space-y-1.5">
    <label htmlFor={id} className="text-sm font-medium">Finance master password</label>
    <div className="relative"><Input id={id} type={visible ? 'text' : 'password'} autoComplete="off" spellCheck={false} maxLength={72} value={value} disabled={disabled} onChange={e => onChange(e.target.value)} className="pr-12" aria-describedby={id + '-help'} />
      <Button type="button" variant="ghost" size="icon" disabled={disabled} className="absolute right-0 top-0" aria-label={visible ? 'Hide finance password' : 'Show finance password'} onClick={() => setVisible(!visible)}>{visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}</Button></div>
    <p id={id + '-help'} className="text-xs text-muted-foreground">This is the owner-managed finance approval password, not your IEOSUIA sign-in password or email verification code. Ask the finance owner if you do not know it; do not keep guessing.</p>
  </div>;
}
