import { useState, useEffect, type ReactNode } from 'react';
import { useAuth } from '@/hooks/useAuth';

export function PortalEntrance({children}: {children:ReactNode}) {
 const {user}=useAuth();
 const [fade]=useState(()=>{
  try {
   const key=`sms-portal-entered:${user?.id}`;
   if(sessionStorage.getItem(key))return false;
   return true;
  }catch{return false;}
 });
 useEffect(()=>{try{sessionStorage.setItem(`sms-portal-entered:${user?.id}`,'true');}catch{/* Optional animation. */}},[user?.id]);
 return <main className={`min-w-0 p-3 sm:p-4 lg:p-6${fade?' portal-entrance':''}`}>{children}</main>;
}
