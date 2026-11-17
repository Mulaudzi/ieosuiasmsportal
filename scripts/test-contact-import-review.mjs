import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import ts from 'typescript';
import readExcelFile from 'read-excel-file/universal';
import {createHash} from 'node:crypto';
const compile=name=>ts.transpileModule(readFileSync(new URL('../src/lib/'+name+'.ts',import.meta.url),'utf8'),{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText;
const url=js=>'data:text/javascript;base64,'+Buffer.from(js).toString('base64');
const legacy=url(compile('contact-phone-review'));
const review=await import(url(compile('contact-import-review').replace(/(['"])\.\/contact-phone-review\1/g,JSON.stringify(legacy))));
const file=await import(url(compile('contact-import-file')));
mkdirSync('.sms-audit.local/import-usability-20261029',{recursive:true});
const {qualifyImportPhone:qualify,reviewImportContact:inspect,importEmailValid:emailValid}=review;
let checks=0;const equal=(actual,expected,label)=>{assert.deepEqual(actual,expected,label);checks++;};
const phones=[],expected=[];
for(let index=0;index<120;index++){
 const local='0'+(600000000+((index*1567919)%300000000));const digits=local.slice(1),international='27'+digits;
 const fullwidth=local.replace(/\d/g,char=>String.fromCharCode(0xFF10+Number(char)));
 const arabic=local.replace(/\d/g,char=>String.fromCharCode(0x0660+Number(char)));
 for(const value of [local,digits,international,'+'+international,'+27 (0) '+digits,'0027'+digits,'00270'+digits,
  local.slice(0,3)+' '+local.slice(3,6)+' '+local.slice(6),local.split('').join('\u00a0'),local.split('').join('\u200b'),
  "'"+local,'’'+local,'="'+local+'"','Mobile: '+local,'TEL: '+local,fullwidth,arabic,
  local.slice(0,3)+'–'+local.slice(3),local+'.0',digits[0]+'.'+digits.slice(1)+'e+8']){
   phones.push(value);expected.push('+'+international);
 }
}
const bad=['27','+27','0027','2771234567','07812345','08212345678','+447700900123','+27821234567 / +27821234568',
 '0821234567;0831234567','0821234567 ext 4','0821234567 or 0831234567','O821234567','08212345??','8.21E+8','8E8','8.21234567e+7',
 '0821234567@person.example','+27+27821234567','=HYPERLINK("https://example.invalid")','="0821234567"&"8"','1'.repeat(201),'+27111234567','-821234567','-0821234567'];
const qualified=phones.map(qualify);
qualified.forEach((result,index)=>equal(result.normalized,expected[index],'Complete formatting variant normalizes safely'));
bad.forEach(value=>equal(qualify(value).normalized,null,'Incomplete, foreign, multi-number, damaged or formula input stays unresolved'));
const values=[...phones,...bad,'','N/A','—','Not provided','＋２７８２１２３４５６７','۰۸۲۱۲۳۴۵۶۷',...['\u00a0','\u0085','\u2007','\u202f','\uFEFF','\u200b','\n','\t'].map(space=>space+'0821234567'+space)];
const emailCases=['a@example.com','A.Name+tag@example.co.za',"o'neil@example.com",'a@b','a..b@example.com','.a@example.com','a.@example.com','a@-example.com','a@example..com','a@example.com; b@example.com','a@éxample.com','person@example.com\n','a'.repeat(65)+'@example.com','person@'+('a'.repeat(64))+'.com'];
const contacts=[
 {name:'Footer'},{name:'Legend',phone:'N/A',email:'—'},{name:'Email',phone:'',email:'email@example.com'},
 {phone:'821234567'},{phone:'+27 (0) 82 123 4567',email:'person@example.com'},
 {phone:'2771234567',email:'person@example.com'},{email:'not-an-email'},
 {phone:'0821234567',email:'not-an-email'},
];
equal(contacts.map(contact=>inspect(contact).status),['skipped','skipped','ready','ready','ready','review','review','review'],'Only genuine issues need review');
equal(inspect(contacts[2]).emailOnly,true,'Email-only retained without SMS phone');
equal(inspect({phone:'NOT B, NOR FS'}).status,'skipped','Noncontact legend text is not a phone candidate');
equal(inspect({phone:'person@example.com'}).emailOnly,true,'Email entered in phone cell is preserved without inventing an SMS number');
equal(inspect({phone:'O821234567'}).status,'review','Damaged digit-bearing number is never silently discarded');
const make=(contact,action='accept',value='',email=contact.email??'')=>({action,original:contact.phone??'',originalEmail:contact.email??'',value,email});
const attempts=[...contacts.map(contact=>({contact})),
 {contact:contacts[5],decision:make(contacts[5],'accept','0721234567')},
 {contact:contacts[5],decision:make(contacts[5],'email_only')},
 {contact:contacts[6],decision:make(contacts[6],'accept','','fixed@example.com')},
 {contact:contacts[7],decision:make(contacts[7],'accept','0821234567','')},
 {contact:contacts[5],decision:make(contacts[5],'accept','2771234567')},
 {contact:contacts[5],decision:{...make(contacts[5],'reject'),original:'changed'}},
 {contact:contacts[5],decision:{...make(contacts[5],'reject'),originalEmail:'changed@example.com'}},
];
equal(inspect(attempts[9].contact,attempts[9].decision).phone,null,'Explicit email-only choice does not invent a phone');
const headers=['name','phone','email'];const rows=contacts.map(contact=>[contact.name??'',contact.phone??'',contact.email??'']);
const decisions=[{row:7,...make(contacts[5],'email_only')},{row:8,...make(contacts[6],'reject')},{row:9,...make(contacts[7],'accept','0821234567','')}];
const prepare={action:'prepare',headers,rows,mapping:{phone:'Phone',email:'Email'},decisions};
const invalid=[
 {...prepare,decisions:[]},
 {...prepare,decisions:[...decisions,decisions[0]]},
 {...prepare,decisions:decisions.map((decision,index)=>index===0?{...decision,original:'changed'}:decision)},
 {...prepare,decisions:decisions.map((decision,index)=>index===0?{...decision,originalEmail:'changed'}:decision)},
 {...prepare,decisions:decisions.map((decision,index)=>index===0?{...decision,action:'accept',value:'2771234567'}:decision)},
 {...prepare,decisions:[...decisions,{row:2,...make(contacts[0],'reject')}]},
 {...prepare,decisions:[...decisions,{row:5,...make(contacts[3],'reject')}]},
 {...prepare,decisions:[{row:'7',...make(contacts[5],'reject')}]},
 {...prepare,mapping:{phone:'phone',email:'phone'}},
 {...prepare,mapping:{}},
 {...prepare,mapping:{phone:'missing'}},
 {...prepare,rows:[['wrong width']]},
 {...prepare,decisions:null},
];
const requests=[{action:'qualify',values},{action:'email',values:emailCases},{action:'review',values:attempts},prepare,...invalid];
const server=JSON.parse(execFileSync('C:/php8/php.exe',['api/tests/run-contact-import-review.php'],{input:JSON.stringify(requests),encoding:'utf8',maxBuffer:20000000,windowsHide:true}));
equal(server[0],values.map(qualify),'PHP/TypeScript qualification is identical');
equal(server[1],emailCases.map(emailValid),'PHP/TypeScript email validation is identical');
equal(server[2],attempts.map(({contact,decision})=>inspect(contact,decision)),'PHP/TypeScript review decisions are identical');
rows.forEach((row,index)=>{const actual=server[3][index+2],decision=decisions.find(choice=>choice.row===index+2);const expectedReview=inspect(contacts[index],decision);for(const key of Object.keys(expectedReview))equal(actual[key],expectedReview[key],'Row-bound server preflight matches preview');});
server.slice(4).forEach(result=>equal(Boolean(result.error),true,'Malformed or stale decisions fail before writes'));
for(const delimiter of [',',';','\t','|'])for(const newline of ['\n','\r\n','\r']){
 const raw=`Name${delimiter}Phone${delimiter}Email${newline}Ada${delimiter}821234567${delimiter}a@example.com${newline}`;
 const prepared=file.prepareContactSheet(file.parseContactCsv(raw));equal(prepared.data,[['Ada','821234567','a@example.com']],'Regional separator and line ending recognized');
}
equal(file.prepareContactSheet(file.parseContactCsv('sep=;\r\nName;Phone\r\nAda;821234567\r\n')).rowNumbers,[3],'Excel sep directive preserves row numbering');
equal(file.parseContactCsv('Name;Phone\n"Doe, Jane";"0821234567"'),[['Name','Phone'],['Doe, Jane','0821234567']],'Quoted commas do not change semicolon parsing');
const utf16=Buffer.concat([Buffer.from([0xFF,0xFE]),Buffer.from('Name\tPhone\r\nJosé\t821234567','utf16le')]);
equal(file.prepareContactSheet(file.parseContactCsv(file.decodeContactCsv(Uint8Array.from(utf16).buffer))).data,[['José','821234567']],'UTF-16 Excel export decoded');
const western=Buffer.from([78,97,109,101,44,80,104,111,110,101,10,74,111,115,233,44,56,50,49,50,51,52,53,54,55]);
equal(file.prepareContactSheet(file.parseContactCsv(file.decodeContactCsv(Uint8Array.from(western).buffer))).data,[['José','821234567']],'Windows-1252 export decoded');
equal(file.contactSheetHasHeaders([['a@example.com'],['b@example.com']]),false,'Headerless email list keeps first contact');
equal(file.contactSheetHasHeaders([['8.21234567E+8']]),false,'Headerless scientific phone keeps first contact');
for(const value of ['０８２１２３４５６７','٠٨٢١٢٣٤٥٦٧',"'0821234567",'="0821234567"','Mobile: 0821234567'])equal(file.contactSheetHasHeaders([[value],['0821234568']]),false,'Headerless formatting variants keep first contact');
const workbookResults=[];
if(process.argv[2]){
 const path=process.argv[2],original=readFileSync(path);const sheets=await readExcelFile(Uint8Array.from(original).buffer,{parseNumber:value=>value});
 for(const sheet of sheets){
  const prepared=file.prepareContactSheet(sheet.data,file.contactSheetHasHeaders(sheet.data));
  const phone=prepared.headers.find(header=>file.contactHeaderField(header)==='phone')??'',email=prepared.headers.find(header=>file.contactHeaderField(header)==='email')??'';
  const contacts=prepared.data.map(row=>({phone:phone?row[prepared.headers.indexOf(phone)]:'',email:email?row[prepared.headers.indexOf(email)]:''}));
  const decisions=contacts.flatMap((contact,index)=>inspect(contact).status==='review'?[{row:index+2,...make(contact,'reject')}]:[]);
  const [backend]=JSON.parse(execFileSync('C:/php8/php.exe',['api/tests/run-contact-import-review.php'],{input:JSON.stringify([{action:'prepare',headers:prepared.headers.map(header=>header.toLowerCase()),rows:prepared.data,mapping:{phone,email},decisions}]),encoding:'utf8',maxBuffer:2000000,windowsHide:true}));
  equal(Boolean(backend.error),false,'Workbook sheet preflight succeeds without production writes');
  contacts.forEach((contact,index)=>{const result=inspect(contact,decisions.find(decision=>decision.row===index+2));for(const key of Object.keys(result))equal(backend[index+2][key],result[key],'Workbook frontend and backend agree');});
  const reviews=contacts.map(contact=>inspect(contact));workbookResults.push({sheet:sheet.sheet,rows:contacts.length,ready:reviews.filter(row=>row.status==='ready').length,automatic_fixes:reviews.filter(row=>row.automatic).length,skipped_empty:reviews.filter(row=>row.status==='skipped').length,needs_review:reviews.filter(row=>row.status==='review').length});
 }
 equal(readFileSync(path),original,'Private workbook unchanged');
 const main=workbookResults.find(sheet=>sheet.sheet==='Main DB');if(main){equal(main.needs_review,0,'Main DB does not demand unnecessary reviews');equal(main.automatic_fixes,47,'All complete missing-zero numbers fixed automatically');}
 writeFileSync('.sms-audit.local/import-usability-20261029/workbook-sha256.txt',createHash('sha256').update(original).digest('hex'));
}
const result={passed:true,checks,qualified_variants:phones.length,unsafe_fixtures:bad.length,php_typescript_parity:true,database_access:false,workbook_sheets:workbookResults};
const out='.sms-audit.local/import-usability-20261029';mkdirSync(out,{recursive:true});writeFileSync(out+'/logic-results.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
