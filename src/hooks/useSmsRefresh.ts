import {useEffect,useRef} from 'react';
export const SMS_REFRESH_MS=15000;
/** Refresh local read models, never query a provider or submit another SMS. */
export function useSmsRefresh(refresh:()=>Promise<unknown>|void) {
  const latest=useRef(refresh);latest.current=refresh;
  useEffect(()=>{
    let active=true,busy=false;
    const run=async()=>{
      if(!active||busy||document.visibilityState==='hidden')return;
      busy=true;
      try{await latest.current();}catch{/* The owning screen presents read errors. */}finally{busy=false;}
    };
    const timer=window.setInterval(()=>void run(),SMS_REFRESH_MS);
    window.addEventListener('focus',run);document.addEventListener('visibilitychange',run);
    return()=>{active=false;window.clearInterval(timer);window.removeEventListener('focus',run);document.removeEventListener('visibilitychange',run);};
  },[]);
}
