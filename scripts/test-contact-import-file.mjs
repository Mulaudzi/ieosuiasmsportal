import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import ts from 'typescript';
import readExcelFile from 'read-excel-file/universal';
import {zipSync,strToU8} from 'fflate';
const source=readFileSync(new URL('../src/lib/contact-import-file.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText;
const {parseContactCsv,prepareContactSheet,contactSheetCsv,contactSheetHasHeaders}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const csv='\uFEFFName,Phone,Email\r\n"Doe, Jane",0799282775,jane@example.com\r\n"Line\nTwo",+27799282775,"a""b"\r\n';
const prepared=prepareContactSheet(parseContactCsv(csv));
assert.equal(prepared.data[0][0],'Doe, Jane');assert.equal(prepared.data[0][1],'0799282775');
assert.deepEqual(prepareContactSheet(parseContactCsv(contactSheetCsv(prepared.headers,prepared.data))),prepared);
assert.throws(()=>prepareContactSheet([['Phone','phone'],['1','2']]));
assert.throws(()=>prepareContactSheet([['Phone',''],['1','2']]));
assert.throws(()=>prepareContactSheet([['Phone']]));assert.throws(()=>parseContactCsv('Name,Phone\n"broken,123'));
const sheet=(phone)=>`<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c><c r="B1" t="inlineStr"><is><t>Phone</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Jane</t></is></c><c r="B2" t="inlineStr"><is><t>${phone}</t></is></c></row></sheetData></worksheet>`;
const xml={
 '[Content_Types].xml':'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
 'xl/workbook.xml':'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Customers" sheetId="1" r:id="rId1"/><sheet name="Suppliers" sheetId="2" r:id="rId2"/></sheets></workbook>',
 'xl/_rels/workbook.xml.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>',
 'xl/worksheets/sheet1.xml':sheet('0799282775'),
 'xl/worksheets/sheet2.xml':sheet('+27799282775')
};
const bytes=zipSync(Object.fromEntries(Object.entries(xml).map(([key,value])=>[key,strToU8(value)])));
const sheets=await readExcelFile(bytes.buffer,{parseNumber:value=>value});
assert.deepEqual(sheets.map(s=>s.sheet),['Customers','Suppliers']);
assert.equal(prepareContactSheet(sheets[0].data).data[0][1],'0799282775');
assert.equal(prepareContactSheet(sheets[1].data).data[0][1],'+27799282775');
const noHeader=[['821234567'],[],['27821234568']];
assert.equal(contactSheetHasHeaders(noHeader),false);
assert.deepEqual(prepareContactSheet(noHeader,false),{headers:['Column 1'],data:[['821234567'],['27821234568']],rowNumbers:[1,3]});
assert.equal(contactSheetHasHeaders([['Name','Phone'],['Person','0821234567']]),true);
assert.equal(prepareContactSheet([['821234567']],false).data.length,1);
assert.equal(prepareContactSheet([['Phone'],[],['0821234567']]).rowNumbers[0],3);
assert.throws(()=>prepareContactSheet(Array.from({length:10001},()=>['0821234567']),false));
console.log('16 CSV/Excel checks passed, including headerless imports, row numbers, worksheets and leading-zero preservation');
