import { useState, useEffect, useCallback, useRef } from "react";
import { Bell, Check, AlertTriangle, Wallet, X, Loader2 } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { ScrollArea } from "@/components/ui/scroll-area";
import { cn } from "@/lib/utils";
import { api } from "@/lib/api";
import { isAccessBlocked } from "@/lib/api-error";
import { useAuth } from "@/hooks/useAuth";

interface Notification { id:string;type:string;title:string;message:string;read:boolean;created_at:string;data?:{campaign_id?:number;payment_id?:number}; }
export function NotificationBell() {
 const {user}=useAuth();const navigate=useNavigate();
 const account=useRef(user?.id);account.current=user?.id;
 const [notifications,setNotifications]=useState<Notification[]>([]);const [unreadCount,setUnreadCount]=useState(0);
 const [loading,setLoading]=useState(false);const [busy,setBusy]=useState(false);const [open,setOpen]=useState(false);
 const [page,setPage]=useState(1);const [hasMore,setHasMore]=useState(false);const [error,setError]=useState("");
 const generation=useRef(0);const mutation=useRef(false);const blocked=useRef(false);
 const load=useCallback(async(manual=false)=>{
  if(blocked.current&&manual!==true)return;
  if(manual===true)blocked.current=false;
  if(!user||account.current!==user.id||mutation.current)return;
  const current=++generation.current;setLoading(true);
  try{
   const response=await api.get<{notifications:Notification[];unread_count:number;has_more?:boolean;sync_available?:boolean}>("/notifications",{page:String(page),per_page:"20"});
   if(current!==generation.current||account.current!==user.id)return;
   if(!response.success)throw Error();
   const payload=(response.data??response) as {notifications?:Notification[];unread_count?:number;has_more?:boolean;sync_available?:boolean};
   setNotifications((payload.notifications??[]).map(n=>({...n,read:n.read===true||String(n.read)==="1"})));
   setUnreadCount(Number(payload.unread_count)||0);setHasMore(Boolean(payload.has_more));
   setError(payload.sync_available===false?"New activity alerts are temporarily unavailable. Existing notifications are shown.":"");
  }catch(reason){if(current===generation.current&&account.current===user.id){blocked.current=isAccessBlocked(reason);setError(isAccessBlocked(reason)?reason.message:"Could not load notifications. Please retry.");}}
  finally{if(current===generation.current)setLoading(false);}
 },[page,user?.id]);
 useEffect(()=>{blocked.current=false;setNotifications([]);setUnreadCount(0);setPage(1);setError("");},[user?.id]);
 useEffect(()=>{const reload=()=>void load();void load();const timer=setInterval(()=>{if(!document.hidden)void load();},30000);window.addEventListener("online",reload);window.addEventListener("focus",reload);return()=>{generation.current++;clearInterval(timer);window.removeEventListener("online",reload);window.removeEventListener("focus",reload);};},[load]);
 const act=async(path:string,remove=false)=>{
  if(mutation.current)return false;mutation.current=true;generation.current++;setBusy(true);setLoading(false);
  try{if(remove)await api.delete(path);else await api.post(path);return true;}
  catch(reason){blocked.current=isAccessBlocked(reason);setError(isAccessBlocked(reason)?reason.message:"Could not update this notification. Please retry.");return false;}
  finally{mutation.current=false;setBusy(false);void load();}
 };
 const visit=async(n:Notification)=>{
  if(!n.read&&!await act(`/notifications/${n.id}/read`))return;
  const id=Number(n.data?.campaign_id);
  if(Number.isInteger(id)&&id>0){setOpen(false);navigate(`/sms-campaigns/${id}`);}
  else if(n.type==="low_credits"||n.type==="payment_complete"||n.data?.payment_id){setOpen(false);navigate("/wallet");}
 };
 const icon=(type:string)=>type==="campaign_complete"?<Check className="h-4 w-4 text-success"/>:type==="low_credits"||type==="payment_complete"?<Wallet className="h-4 w-4 text-primary"/>:type.includes("failed")?<AlertTriangle className="h-4 w-4 text-destructive"/>:<Bell className="h-4 w-4"/>;
 return <Popover open={open} onOpenChange={value=>{setOpen(value);if(value)void load();}}>
  <PopoverTrigger asChild><Button aria-label={`Notifications, ${unreadCount} unread`} variant="ghost" size="icon" className="relative"><Bell className="h-5 w-5"/>{unreadCount>0&&<span className="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-destructive text-xs text-destructive-foreground">{unreadCount>9?"9+":unreadCount}</span>}</Button></PopoverTrigger>
  <PopoverContent className="w-80 max-w-[calc(100vw-1rem)] p-0" align="end">
   <div className="flex items-center justify-between border-b p-3"><h3 className="font-semibold">Notifications</h3>{unreadCount>0&&<Button variant="ghost" size="sm" disabled={busy} onClick={()=>void act("/notifications/read-all")}>Mark all read</Button>}</div>
   {error&&<div role="alert" className="p-3 text-sm">{error}<Button variant="ghost" size="sm" onClick={()=>void load(true)}>Retry</Button></div>}
   <ScrollArea className="h-[300px]">{loading&&notifications.length===0?<div role="status" className="p-6"><Loader2 className="h-5 w-5 animate-spin"/>Loading notifications</div>:notifications.length===0?<p className="p-6 text-center text-sm text-muted-foreground">{error?"Notifications unavailable":page===1?"No notifications yet":"No more notifications"}</p>:<div className="divide-y">{notifications.map(n=><div key={n.id} className={cn("flex gap-1 p-2",!n.read&&"bg-primary/5")}><button type="button" disabled={busy} onClick={()=>void visit(n)} className="flex min-w-0 flex-1 gap-3 rounded p-1 text-left hover:bg-muted focus-visible:outline focus-visible:outline-primary"><span className="mt-1">{icon(n.type)}</span><span className="min-w-0"><span className={cn("block text-sm",!n.read&&"font-semibold")}>{n.title}</span><span className="mt-1 block text-xs text-muted-foreground">{n.message}</span><span className="mt-1 block text-xs text-muted-foreground">{n.created_at}</span></span></button><Button aria-label={`Dismiss ${n.title}`} variant="ghost" size="icon" disabled={busy} className="h-8 w-8 shrink-0" onClick={()=>void act(`/notifications/${n.id}`,true)}><X className="h-3 w-3"/></Button></div>)}</div>}</ScrollArea>
   <div className="flex items-center justify-between border-t p-2"><Button variant="ghost" size="sm" disabled={page<=1||loading||busy} onClick={()=>setPage(p=>p-1)}>Newer</Button><span className="text-xs">Page {page}</span><Button variant="ghost" size="sm" disabled={!hasMore||loading||busy} onClick={()=>setPage(p=>p+1)}>Older</Button></div>
  </PopoverContent>
 </Popover>;
}
