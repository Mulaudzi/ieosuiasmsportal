import { api } from '@/lib/api';
export interface ContactTag {id:string;name:string;contact_count?:number;}
export interface ContactFilters {group_id?:string;group_ids?:string;tag_ids?:string;search?:string;sort?:string;}
export const contactTagsApi={
  list:async()=>((await api.get('/contact-tags')).tags as ContactTag[]),
  save:async(name:string,id?:string)=>((id?await api.put(`/contact-tags/${id}`,{name}):await api.post('/contact-tags',{name})).tag as ContactTag),
  remove:async(id:string)=>api.delete(`/contact-tags/${id}`),
  assign:async(contact_ids:string[],tag_ids:string[],operation:'add'|'remove')=>api.post('/contact-tags/assign',{contact_ids,tag_ids,operation}),
  smsDraft:async(filters:ContactFilters,draft_id:string)=>((await api.post('/contact-tags/sms-draft',{...filters,draft_id})).draft as {id:string}),
};
