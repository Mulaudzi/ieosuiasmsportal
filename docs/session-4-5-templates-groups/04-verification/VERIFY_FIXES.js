#!/usr/bin/env node

/**
 * Verification Script - Check that all fixes are properly applied
 * 
 * Run this to verify the debug fixes:
 * node VERIFY_FIXES.js
 */

const fs = require('fs');
const path = require('path');

const FIXES = [
  {
    name: "ApiResponse Type Definition",
    file: "src/lib/api.ts",
    checks: [
      { pattern: /\[key: string\]: unknown/, desc: "Index signature for dynamic properties" },
      { pattern: /meta\?:/, desc: "Meta object for pagination" },
    ]
  },
  {
    name: "Contacts.tsx - Groups Parsing",
    file: "src/pages/Contacts.tsx",
    checks: [
      { pattern: /groupsRes\.groups as any\[\]/, desc: "Groups at top level" },
    ]
  },
  {
    name: "Contacts.tsx - Pagination Fix",
    file: "src/pages/Contacts.tsx",
    checks: [
      { pattern: /contactsRes\.meta\?\.total/, desc: "Total from meta object" },
    ]
  },
  {
    name: "AutomatedTestDashboard.tsx - DB Test Fix",
    file: "src/pages/AutomatedTestDashboard.tsx",
    checks: [
      { pattern: /createdId = createResponse\.id \|\| createResponse\.contact\?\.id/, desc: "Direct ID access from response" },
    ]
  },
  {
    name: "AutomatedTestDashboard.tsx - Response Logging",
    file: "src/pages/AutomatedTestDashboard.tsx",
    checks: [
      { pattern: /result\.response_body = response;/, desc: "Full response captured (not just data)" },
    ]
  },
  {
    name: "CreateEmailCampaign.tsx - Groups Fix",
    file: "src/pages/CreateEmailCampaign.tsx",
    checks: [
      { pattern: /groupsRes\.groups as ContactGroup\[\]/, desc: "Groups at top level" },
    ]
  },
  {
    name: "CreateEmailCampaign.tsx - Contacts Fix",
    file: "src/pages/CreateEmailCampaign.tsx",
    checks: [
      { pattern: /response\.data as any\[\]/, desc: "Contacts data parsed correctly" },
    ]
  },
];

console.log("\n✅ VERIFICATION SCRIPT - IEOSUIA SMS Portal Debug Fixes\n");
console.log("Checking if all fixes have been applied correctly...\n");

let allPassed = true;

FIXES.forEach(fix => {
  console.log(`📋 ${fix.name}`);
  console.log(`   File: ${fix.file}`);
  
  const filePath = path.join(__dirname, fix.file);
  
  if (!fs.existsSync(filePath)) {
    console.log(`   ❌ FILE NOT FOUND\n`);
    allPassed = false;
    return;
  }
  
  const content = fs.readFileSync(filePath, 'utf8');
  
  fix.checks.forEach(check => {
    if (check.pattern.test(content)) {
      console.log(`   ✅ ${check.desc}`);
    } else {
      console.log(`   ❌ MISSING: ${check.desc}`);
      allPassed = false;
    }
  });
  
  console.log();
});

console.log("---\n");

if (allPassed) {
  console.log("✅ ALL FIXES VERIFIED - Ready to test!\n");
  console.log("Next steps:");
  console.log("1. Go to http://localhost:5173/contacts");
  console.log("2. Verify groups sidebar shows contact groups");
  console.log("3. Go to http://localhost:5173/test-dashboard");
  console.log("4. Click 'Run All Tests' and verify DB tests pass\n");
} else {
  console.log("❌ SOME FIXES ARE MISSING - Check errors above\n");
  console.log("Please ensure all edits were applied correctly.\n");
  process.exit(1);
}

