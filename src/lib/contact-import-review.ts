import { analyzeSouthAfricanPhone, type PhoneAnalysis } from './contact-phone-review';

export interface ImportContact { name?: string; phone?: string; email?: string }
export interface ImportDecision {
  action: 'accept' | 'reject' | 'email_only';
  original: string;
  originalEmail: string;
  value?: string;
  email?: string;
}
export interface ImportContactReview {
  status: 'ready' | 'review' | 'skipped' | 'rejected';
  phone: string | null;
  email: string;
  emailOnly: boolean;
  automatic: boolean;
  reason: string;
}

export function importContactValue(value = ''): string {
  const clean=value.replace(/[\u200B-\u200F\u2060\uFEFF]/g,'').replace(/\u0085/g,' ').trim();
  return /^(?:n\/?a|n\.a\.|none|null|nil|unknown|not known|not available|not provided|no phone|no number|no email|tbc|tba|[-–—])$/i.test(clean)?'':clean;
}

// Deliberately supports ordinary email addresses, not display-name lists or formulas.
export function importEmailValid(value: string): boolean {
  if(value.length>254||!value.includes('@'))return false;
  const [local,domain,...extra]=value.split('@');
  return !extra.length&&local.length>0&&local.length<=64&&!local.startsWith('.')&&!local.endsWith('.')&&!local.includes('..')
    && /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+$/.test(local)
    && domain.length<=253&&domain.split('.').length>=2&&domain.split('.').every(label=>label.length<=63&&/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/.test(label));
}

/** Import-only, deterministic repairs; never fabricate subscriber digits. */
export function qualifyImportPhone(input: string): PhoneAnalysis & { automatic: boolean } {
  let value=importContactValue(input);
  const failure=(reason:string)=>({normalized:null,proposed:'',reason,automatic:false});
  if(value.length>200)return failure('This cell is too long to be one phone number. Enter one complete mobile number.');
  value=value.replace(/[０-９٠-٩۰-۹]/g,char=>String(char.charCodeAt(0)-(char>='０'?0xFF10:char>='۰'?0x06F0:0x0660)))
    .replace(/＋/g,'+').replace(/[‐‑‒–—−]/g,'-').replace(/^['’]/,'')
    .replace(/^(?:tel(?:ephone)?|phone|mobile|cell(?:phone)?|contact|whatsapp)\s*:\s*/i,'');
  // A quoted numeric literal is data; no spreadsheet formula is ever executed.
  const literal=value.match(/^="([+0-9\s().-]+)"$/);if(literal)value=literal[1];
  if(value.startsWith('-'))return failure('Negative values are not phone numbers. Enter the verified full number.');
  const scientific=value.match(/^(\d+)\.(\d+)[eE]\+?(\d{1,2})$/);
  if(scientific){
    const digits=scientific[1]+scientific[2],places=Number(scientific[3])-scientific[2].length;
    if(digits.length<9||places<0||digits.length+places>15)return failure('Scientific notation may have lost digits. Enter the verified full number.');
    value=digits+'0'.repeat(places);
  }else if(/^[+0-9.]+[eE][+-]?\d*$/.test(value))return failure('Scientific notation may have lost digits. Enter the verified full number.');
  if(/^\+?\d{9,13}\.0+$/.test(value))value=value.replace(/\.0+$/,'');
  const compact=value.replace(/[\s().-]/g,'');
  if(/^00270[678]\d{8}$/.test(compact))value='0'+compact.slice(5);
  const analysis=analyzeSouthAfricanPhone(value);
  if(analysis.normalized)return {...analysis,automatic:value!==input};
  if(analysis.proposed){
    const repaired=analyzeSouthAfricanPhone(analysis.proposed).normalized;
    if(repaired)return {...analysis,normalized:repaired,automatic:true};
  }
  return {...analysis,automatic:false};
}

export function reviewImportContact(contact: ImportContact, decision?: ImportDecision): ImportContactReview {
  const original=contact.phone??'',originalEmail=contact.email??'';
  let rawPhone=importContactValue(original),rawEmail=importContactValue(originalEmail);
  if(!rawEmail&&importEmailValid(rawPhone)){rawEmail=rawPhone;rawPhone='';}
  // Legend text in a phone cell is not a phone candidate. Damaged digit-bearing
  // values remain reviewable rather than being silently discarded.
  if(rawPhone&&!/\p{Nd}/u.test(rawPhone))rawPhone='';
  const result=(status:ImportContactReview['status'],phone:string|null,email:string,automatic=false,reason=''):ImportContactReview=>({status,phone,email,emailOnly:status==='ready'&&!phone&&Boolean(email),automatic,reason});
  if(!rawPhone&&!rawEmail)return result('skipped',null,'',false,'No phone number or email.');
  const analysis=qualifyImportPhone(original);
  const emailValid=!rawEmail||importEmailValid(rawEmail);
  if(emailValid&&(analysis.normalized||(!rawPhone&&rawEmail)))return result('ready',analysis.normalized,rawEmail,analysis.automatic);
  const current=decision?.original===original&&decision.originalEmail===originalEmail?decision:undefined;
  if(current?.action==='reject')return result('rejected',null,rawEmail);
  if(current){
    const email=importContactValue(current.email??originalEmail);
    const phone=qualifyImportPhone(current.value??original);
    if(current.action==='email_only'&&email&&importEmailValid(email))return result('ready',null,email);
    if(current.action==='accept'&&(!email||importEmailValid(email))&&(phone.normalized||(!importContactValue(current.value??original)&&email)))return result('ready',phone.normalized,email);
  }
  const reason=[!emailValid?'Check or remove the email address.':'',!analysis.normalized&&rawPhone?analysis.reason:!rawPhone&&!emailValid?'Add a complete phone number or correct the email.':''].filter(Boolean).join(' ');
  return result('review',analysis.normalized,rawEmail,false,reason);
}
