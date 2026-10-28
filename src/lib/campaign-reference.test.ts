import {expect,it} from 'vitest';
import {campaignReference} from './campaign-reference';
import {campaignStatus} from './campaign-status';
it('keeps stable unique display references without changing URL IDs',()=>{const references=new Set(Array.from({length:10000},(_,i)=>campaignReference({id:i+1})));expect(references.size).toBe(10000);expect(campaignReference({id:26})).toBe(campaignReference({id:'26'}));expect(campaignReference({id:26})).toMatch(/^SMS-[A-Z0-9]{7}-[A-Z0-9]{2}$/);});
it('separates held-only results from actively processing work',()=>{expect(campaignStatus({state:'processing',held_submission_count:1,queued_count:1,processing_count:0}).label).toBe('Awaiting provider confirmation');expect(campaignStatus({state:'processing',held_submission_count:1,queued_count:2,processing_count:1}).label).toBe('Processing');});
