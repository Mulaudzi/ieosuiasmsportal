import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(process.argv[2] || '.');
const disclosure = /(?:not|non)[\s-]*VAT[\s-]*registered|not\s+registered\s+for\s+VAT/iu;
let checked = 0;
function scan(folder) {
  for (const entry of readdirSync(folder, { withFileTypes: true })) {
    if (['vendor', 'tests', 'node_modules'].includes(entry.name)) continue;
    const file = path.join(folder, entry.name);
    if (entry.isDirectory()) scan(file);
    else if (/\.(?:php|tsx?|jsx?|json|html|txt|xml)$/i.test(entry.name) && !/\.(?:test|spec)\./.test(entry.name)) {
      assert(!disclosure.test(readFileSync(file, 'utf8')), `VAT registration disclosure: ${path.relative(root, file)}`);
      checked++;
    }
  }
}
for (const name of ['src', 'api', 'public']) scan(path.join(root, name));
for (const name of ['src/pages/Pricing.tsx', 'src/components/wallet/BuyCreditsModal.tsx', 'api/controllers/CreditPurchaseController.php', 'api/services/EmailService.php']) {
  assert(readFileSync(path.join(root, name), 'utf8').includes('We do not charge VAT.'), name);
}
assert(readFileSync(path.join(root, 'api/services/PdfReceiptService.php'), 'utf8').includes('<span>Not charged</span>'));
console.log(`PASS: ${checked} customer communication source files scanned; all five VAT surfaces use neutral wording.`);
