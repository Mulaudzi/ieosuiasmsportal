"""Create an allowlisted, credential-free local review bundle; never connects or deploys."""
from datetime import datetime, timezone
import difflib
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / '.sms-audit.local' / 'whatsapp-customer-connect-20261103' / 'review'
FILES = [
    'api/domain/MetaWhatsAppConnectConfig.php', 'api/domain/MetaWhatsAppConnections.php',
    'api/services/MetaWhatsAppOnboardingClient.php', 'api/migrations/026_whatsapp_customer_connections.php',
    'api/whatsapp-connect.php', 'api/whatsapp-meta-oauth.php', 'api/bin/whatsapp-connect-cleanup.php',
    'api/.env.whatsapp.example', 'api/tests/run-whatsapp-connect.php',
    'src/lib/whatsapp-connect.ts', 'src/components/settings/WhatsAppConnections.tsx',
    'src/components/settings/WhatsAppConnections.test.tsx', 'scripts/test-whatsapp-connect-http.py',
    'scripts/test-whatsapp-connect-browser.mjs', 'scripts/prepare-whatsapp-connect-review.py',
    'docs/verification/whatsapp-customer-connect-20261103.md',
]
DEPENDENCIES = [
    'api/config/database.php', 'api/core/Auth.php', 'api/core/Request.php',
    'api/core/Response.php', 'api/core/JWT.php', 'api/core/QueryBuilder.php',
    'api/domain/SmsLoginSecurity.php', 'api/domain/CentralAdminAccess.php',
    'api/.htaccess', 'api/bin/.htaccess', 'api/config/.htaccess',
    'api/index.php', 'api/providers/MetaWhatsAppProvider.php', 'api/whatsapp-meta-webhook.php',
]
OUT.mkdir(parents=True, exist_ok=True)
entries = []
for relative in FILES:
    source = ROOT / relative
    assert source.resolve().is_relative_to(ROOT)
    data = source.read_bytes()
    destination = OUT / 'files' / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(data)
    entries.append({'path': relative, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})

# Reconstruct only our narrow Settings delta. Do not ship the unrelated existing
# working-tree changes in Settings or the rest of this checkout as a release.
after = (ROOT / 'src/pages/Settings.tsx').read_text(encoding='utf-8')
before = after
for new, old in [
    ("import { WhatsAppConnections } from '@/components/settings/WhatsAppConnections';\n", ''),
    ("['profile','organization','security','get-started','compliance','whatsapp']", "['profile','organization','security','get-started','compliance']"),
    ('lg:grid-cols-6 [&_button]', 'lg:grid-cols-5 [&_button]'),
    ('          <TabsTrigger value="whatsapp" aria-label="WhatsApp">WhatsApp</TabsTrigger>\n', ''),
    ('        <TabsContent value="whatsapp"><WhatsAppConnections /></TabsContent>\n', ''),
]:
    if before.count(new) != 1:
        raise RuntimeError('Settings changed: inspect the task-only patch before regenerating')
    before = before.replace(new, old, 1)
patch = ''.join(difflib.unified_diff(before.splitlines(keepends=True), after.splitlines(keepends=True),
                                     fromfile='a/src/pages/Settings.tsx', tofile='b/src/pages/Settings.tsx'))
(OUT / 'settings-task-only.patch').write_text(patch, encoding='utf-8', newline='\n')
manifest = {
    'prepared_at_utc': datetime.now(timezone.utc).isoformat(),
    'state': 'LOCAL_REVIEW_ONLY_NOT_DEPLOYED',
    'redirect_uri_planned': 'https://sms.ieosuia.com/api/whatsapp-meta-oauth.php',
    'connect_enabled': False, 'sending_enabled': False,
    'files': entries,
    'settings_delta': {'path': 'settings-task-only.patch', 'sha256': hashlib.sha256(patch.encode()).hexdigest(),
                       'note': 'Apply to a verified live source baseline; do not replace Settings with the dirty local file.'},
    'local_dependency_hashes_not_upload_allowlist': [
        {'path': name, 'sha256': hashlib.sha256((ROOT / name).read_bytes()).hexdigest()} for name in DEPENDENCIES
    ],
    'validation': {'php_connection_checks': 76, 'php_http_auth_header_checks': 26, 'ui_unit_tests': 7,
                   'chromium_checks': 18, 'existing_provider_checks': 48, 'existing_webhook_checks': 31,
                   'typecheck': 'passed', 'focused_eslint': 'passed', 'vite_production_build': 'passed'},
    'live_checks_not_performed': ['hosting MySQL rehearsal/apply', 'live source baseline/readback',
                                 'Meta-hosted state forwarding', 'real customer token grants',
                                 'live browser signup round-trip', 'Meta App Review approval'],
    'private_config_not_in_bundle': ['WHATSAPP_CONNECT_CONFIG_ID', 'WHATSAPP_CONNECT_TOKEN_KEY',
                                     'existing WHATSAPP_META_APP_SECRET'],
}
(OUT / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
print(f'Prepared {len(entries)} allowlisted review files and a task-only Settings patch. No deployment or secrets.')
