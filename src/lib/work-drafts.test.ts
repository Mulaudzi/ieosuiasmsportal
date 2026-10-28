import {afterEach, expect, it, vi} from 'vitest';
import {getWorkDraft, isPurchaseDraft, isSmsDraft, readPurchaseRecovery, saveWorkDraft} from './work-drafts';
const mocks=vi.hoisted(()=>({get:vi.fn(),put:vi.fn()}));
vi.mock('@/lib/api',()=>({api:mocks}));
const purchase={version:1,step:'payment',credits:1000,method:'eft',requestKey:'stable-key'} as const;
afterEach(()=>{localStorage.clear();vi.clearAllMocks();});
it('validates purchase recovery and rejects bad keys, quantities and method names',()=>{
 expect(isPurchaseDraft(purchase)).toBe(true);
 for(const patch of [{requestKey:undefined},{credits:NaN},{credits:1.5},{credits:-1},{method:'unknown'},{step:'paid'}])expect(isPurchaseDraft({...purchase,...patch})).toBe(false);
 expect(isPurchaseDraft({...purchase,credits:0})).toBe(true);
});
it('keeps browser recovery scoped to the signed-in account',()=>{
 localStorage.setItem('sms-purchase-draft:v1:other',JSON.stringify({payload:purchase}));expect(readPurchaseRecovery('owner')).toBeNull();
 localStorage.setItem('sms-purchase-draft:v1:owner',JSON.stringify({payload:purchase,reference:{id:'draft-id',title:'Saved',version:3}}));
 expect(readPurchaseRecovery('owner')?.reference?.version).toBe(3);
 localStorage.setItem('sms-purchase-draft:v1:owner','{broken');expect(readPurchaseRecovery('owner')).toBeNull();
});
it('safely ignores storage failures',()=>{
 const spy=vi.spyOn(Storage.prototype,'getItem').mockImplementation(()=>{throw Error('Blocked');});expect(readPurchaseRecovery('owner')).toBeNull();spy.mockRestore();
});
it('retrieves the selected kind only, never a purchase as an SMS draft',async()=>{
 mocks.get.mockResolvedValue({draft:{id:'1',version:1,kind:'purchase',payload:purchase}});
 await expect(getWorkDraft('1','sms')).rejects.toThrow('could not be opened');
 await expect(getWorkDraft('1','purchase')).resolves.toMatchObject({kind:'purchase',payload:purchase});
});
it('saves intent and its concurrency version, not a quote or payment request',async()=>{
 mocks.put.mockResolvedValue({draft:{id:'stable-id',version:2,kind:'purchase',payload:purchase}});
 await saveWorkDraft('stable-id','purchase','Credit top-up',purchase,1);
 expect(mocks.put).toHaveBeenCalledWith('/drafts/stable-id',{kind:'purchase',title:'Credit top-up',payload:purchase,expected_version:1});
 expect(mocks.get).not.toHaveBeenCalled();
});
it('validates the existing SMS browser recovery shape',()=>{
 const sms={version:1,step:5,name:'Test',manual:'0638082493',groupIds:[],contactIds:[],excluded:[],selectedTemplate:'none',content:'Hello',includeOptOut:false,schedule:'',submissionKey:'stable-key'};
 expect(isSmsDraft(sms)).toBe(true);expect(isSmsDraft({...sms,includeOptOut:'false'})).toBe(false);expect(isSmsDraft({...sms,contactIds:[1]})).toBe(false);
});
