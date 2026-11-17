import { useEffect, useState } from 'react';
import { useAuth } from '@/hooks/useAuth';
const read=(key:string)=>{try{return localStorage.getItem(key)!=='hidden';}catch{return true;}};
export function usePortalHints() {
  const {user}=useAuth();const key=`sms-portal:hints:v1:${user?.id||'guest'}`;
  const [preference,setPreference]=useState(()=>({key,visible:read(key)}));
  useEffect(()=>{const update=()=>setPreference({key,visible:read(key)});update();window.addEventListener('storage',update);window.addEventListener('portal-hints-changed',update);return()=>{window.removeEventListener('storage',update);window.removeEventListener('portal-hints-changed',update);};},[key]);
  const setVisible=(visible:boolean)=>{setPreference({key,visible});try{localStorage.setItem(key,visible?'visible':'hidden');window.dispatchEvent(new Event('portal-hints-changed'));}catch{/* Keep the choice for this visit when browser storage is unavailable. */}};
  return {visible:preference.key===key?preference.visible:read(key),setVisible};
}
