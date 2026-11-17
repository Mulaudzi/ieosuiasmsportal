import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';

const base = process.argv[2] || 'http://127.0.0.1:4218';
const output = '.sms-audit.local/contact-groups-validation-20261014';
mkdirSync(output, {recursive: true});
const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
let checks = 0;
try {
  for (const width of [1440, 375, 320]) {
    const context = await browser.newContext({viewport: {width, height: 950}});
    const user = {id: 'contact-fixture', name: 'Contact fixture', email: 'fixture@example.invalid', role: 'user', email_verified: true};
    await context.addInitScript(user => {
      localStorage.setItem('auth_user', JSON.stringify(user));
      localStorage.setItem('auth_token', 'fixture-only');
      localStorage.setItem('auth_token_issued', String(Date.now()));
      localStorage.setItem('ieosuia_cookie_consent', '{}');
    }, user);
    const contacts = [
      {id:'1', name:'Ada', phone:'+27820000001', email:'ada@example.invalid', subscription_status:'subscribed', created_at:'2026-01-01'},
      {id:'2', name:'Ben', phone:'+27820000002', email:'ben@example.invalid', subscription_status:'unsubscribed', created_at:'2026-01-01'},
      {id:'3', name:'Cara', phone:'+27820000003', email:'cara@example.invalid', subscription_status:'subscribed', created_at:'2026-01-01'},
    ];
    let groups = [{id:'1', name:'Source', members:['1','2']}, {id:'2', name:'Destination', members:['2','3']}];
    const imports = [], transfers = [], searches = [], errors = [], edits = [];
    let transferFails = false;
    await context.route('**/api/**', async route => {
      const url = new URL(route.request().url());
      let body = {success: true};
      if (url.pathname.endsWith('/auth/user')) body = {...body, user};
      else if (url.pathname.endsWith('/notifications')) body = {...body, notifications:[], unread_count:0, has_more:false};
      else if (url.pathname.endsWith('/wallet')) body = {...body, wallet:{balance:35, reserved:0, sms_credits:100}};
      else if (url.pathname.endsWith('/contact-groups')) body = {...body, total_contacts:contacts.length, groups:[{id:'uncategorized', name:'Uncategorized', is_virtual:true, contact_count:0}, ...groups.map(g => ({id:g.id,name:g.name,contact_count:g.members.length}))]};
      else if (/\/contacts\/\d+$/.test(url.pathname) && route.request().method() === 'PUT') {
        edits.push(route.request().postDataJSON());
        body = {...body, contact: contacts[0]};
      }
      else if (url.pathname.endsWith('/contacts/import')) {
        imports.push(route.request().postData());
        body = {...body, imported:1, duplicates:1, failed:0, existing_added_to_group:1};
      } else if (url.pathname.endsWith('/contacts/transfer-group')) {
        const input = route.request().postDataJSON(); transfers.push(input);
        if (transferFails) return route.fulfill({status:409, json:{success:false,message:'Some selected contacts are no longer in the source group. Refresh the list and try again.'}});
        const source = groups.find(g => g.id === input.source_group_id);
        const target = groups.find(g => g.id === input.target_group_id);
        const ids = input.mode === 'merge' ? [...source.members] : input.ids;
        let added=0, duplicates=0;
        for (const id of ids) { if (target.members.includes(id)) duplicates++; else {target.members.push(id);added++;} }
        if (input.mode !== 'copy') source.members=source.members.filter(id => !ids.includes(id));
        if (input.delete_source) groups=groups.filter(g => g.id !== source.id);
        body = {...body, added, already_present:duplicates, matched:ids.length, removed_from_source:input.mode==='copy'?0:ids.length, source_deleted:!!input.delete_source};
      } else if (url.pathname.endsWith('/contacts')) {
        const groupId=url.searchParams.get('group_id');
        const search=url.searchParams.get('search') || '';
        searches.push({groupId, search});
        const rows=contacts.filter(c => (!groupId || groups.find(g => g.id===groupId)?.members.includes(c.id)) && c.name.toLowerCase().includes(search.toLowerCase()));
        body={...body, data:rows.map(c=>({...c,group_id:groups.filter(g=>g.members.includes(c.id)).map(g=>g.id).join(','),group_name:groups.filter(g=>g.members.includes(c.id)).map(g=>g.name).join(', ')})),meta:{current_page:1,per_page:50,total:rows.length,opted_out_total:1}};
      }
      return route.fulfill({json:body});
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base+'/contacts');
    const chooseGroup = async name => {
      await page.getByRole('button', {name,exact:true}).click();
      await page.getByRole('heading',{name,exact:true}).waitFor();
      await page.getByRole('button',{name:'Import',exact:true}).waitFor();
    };
    const chooseOption = async (id, label) => {
      await page.locator('#'+id).click();
      await page.getByRole('option',{name:label,exact:true}).click();
    };
    await chooseGroup('Source');
    await page.getByRole('button',{name:'Import',exact:true}).click();
    const dialog = page.getByRole('dialog');
    assert.match(await dialog.innerText(), /Import destination: Source/); checks++;
    await chooseOption('import-destination','Destination');
    await page.locator('input[type=file]').setInputFiles({name:'contacts.csv',mimeType:'text/csv',buffer:Buffer.from('Name,Phone,Email\nFixture,0820000001,fixture@example.invalid\n')});
    await dialog.getByRole('button',{name:'Continue',exact:true}).click();
    assert.match(await dialog.innerText(), /Import destination: Destination/); checks++;
    await dialog.getByRole('button',{name:'Import 1 Contacts to Destination',exact:true}).click();
    await dialog.getByRole('button',{name:'Done',exact:true}).waitFor();
    assert.match(imports[0], /name="group_id"\r?\n\r?\n2/); checks++;
    assert.match(await dialog.innerText(), /1 existing contacts added to Destination/); checks++;
    await dialog.getByRole('button',{name:'Done',exact:true}).click();
    await page.getByRole('button',{name:'Import',exact:true}).click();
    assert.match(await dialog.innerText(), /Import destination: Source/); checks++;
    await chooseOption('import-destination','All Contacts (no group)');
    await page.locator('input[type=file]').setInputFiles({name:'contacts.csv',mimeType:'text/csv',buffer:Buffer.from('Name,Phone\nFixture,0820000001\n')});
    await dialog.getByRole('button',{name:'Continue',exact:true}).click();
    await dialog.getByRole('button',{name:'Import 1 Contacts to All Contacts',exact:true}).click();
    await dialog.getByRole('button',{name:'Done',exact:true}).waitFor();
    assert.ok(!imports[1].includes('name="group_id"')); checks++;
    await dialog.getByRole('button',{name:'Done',exact:true}).click();

    await page.getByRole('checkbox',{name:'Select Ada',exact:true}).click();
    await page.getByRole('button',{name:'Copy to Group',exact:true}).click();
    await page.locator('#transfer-target').click();
    assert.equal(await page.getByRole('option',{name:'Uncategorized',exact:true}).count(),0); checks++;
    assert.equal(await page.getByRole('option',{name:'Source',exact:true}).count(),0); checks++;
    await page.getByRole('option',{name:'Destination',exact:true}).click();
    await page.screenshot({path:`${output}/copy-${width}.png`, fullPage:true});
    await dialog.getByRole('button',{name:'Copy contacts',exact:true}).click();
    await dialog.waitFor({state:'hidden'});
    assert.deepEqual(transfers[0],{mode:'copy',target_group_id:'2',source_group_id:'1',ids:['1']}); checks++;
    assert.deepEqual(groups[0].members,['1','2']); checks++;
    const adaRow = page.getByRole('row').filter({has:page.getByRole('checkbox',{name:'Select Ada',exact:true})});
    await adaRow.getByRole('button',{name:'Edit contact',exact:true}).click();
    assert.match(await dialog.innerText(), /Keep existing groups/); checks++;
    await dialog.getByLabel('Name').fill('Ada updated');
    await dialog.getByRole('button',{name:'Save Changes',exact:true}).click();
    await dialog.waitFor({state:'hidden'});
    assert.equal(Object.hasOwn(edits[0],'group_id'),false); checks++;
    await chooseGroup('Source');
    await page.getByRole('checkbox',{name:'Select Ada',exact:true}).click();
    await page.getByRole('button',{name:'Move to Group',exact:true}).click();
    await chooseOption('transfer-target','Destination');
    transferFails=true;
    await dialog.getByRole('button',{name:'Move contacts',exact:true}).click();
    await page.getByText('Some selected contacts are no longer in the source group. Refresh the list and try again.',{exact:true}).first().waitFor();
    assert.ok(await dialog.isVisible()); checks++;
    assert.deepEqual(groups[0].members,['1','2']); checks++;
    transferFails=false;
    await dialog.getByRole('button',{name:'Move contacts',exact:true}).click();
    await dialog.waitFor({state:'hidden'});
    assert.deepEqual(groups[0].members,['2']); checks++;
    assert.equal(groups[1].members.filter(id=>id==='1').length,1); checks++;

    await chooseGroup('Source');
    await page.getByRole('checkbox',{name:'Select Ben',exact:true}).click();
    await chooseGroup('Destination');
    assert.equal(await page.getByRole('button',{name:'Move to Group',exact:true}).count(),0); checks++;
    await chooseGroup('Source');
    await page.getByPlaceholder('Search contacts...').fill('Cara');
    await page.getByText('No matching contacts',{exact:true}).waitFor();
    assert.ok(searches.some(s=>s.groupId==='1'&&s.search==='Cara')); checks++;
    await page.getByPlaceholder('Search contacts...').fill('');
    await page.getByRole('button',{name:'Actions for Source',exact:true}).click();
    await page.getByRole('menuitem',{name:'Merge into another group',exact:true}).click();
    await chooseOption('transfer-target','Destination');
    assert.equal(await dialog.getByRole('checkbox').getAttribute('data-state'),'unchecked'); checks++;
    await dialog.getByRole('checkbox').click();
    await page.screenshot({path:`${output}/merge-${width}.png`, fullPage:true});
    await dialog.getByRole('button',{name:'Merge groups',exact:true}).click();
    await dialog.waitFor({state:'hidden'});
    assert.deepEqual(transfers.at(-1),{mode:'merge',target_group_id:'2',source_group_id:'1',delete_source:true}); checks++;
    assert.equal(groups.length,1); checks++;
    assert.equal(contacts[1].subscription_status,'unsubscribed'); checks++;
    await page.getByRole('button',{name:'All Contacts',exact:false}).click();
    await page.getByRole('heading',{name:'All Contacts',exact:true}).waitFor();
    assert.match(await page.getByRole('button',{name:'All Contacts',exact:false}).innerText(), /3/); checks++;
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)); checks++;
    assert.deepEqual(errors,[]); checks++;
    await context.close();
  }
  console.log(`${checks} contact import/group browser checks passed at desktop and mobile widths. All API requests mocked; no production writes.`);
} finally { await browser.close(); }
