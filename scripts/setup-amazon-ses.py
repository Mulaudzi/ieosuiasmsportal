"""Narrow SES onboarding. No sending, IAM keys, paid IPs, DNS or production-portal writes."""
import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path

import botocore.session
from botocore.auth import SigV4Auth
from botocore.awsrequest import AWSRequest
from botocore.config import Config
from botocore.exceptions import ClientError
from botocore.httpsession import URLLib3Session

REGION = 'eu-north-1'
DOMAIN = 'campaigns.ieosuia.com'
CONFIGURATION_SET = 'ieosuia-portal-campaigns'
MAIL_FROM = 'bounce.campaigns.ieosuia.com'
ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / '.sms-audit.local/amazon-ses-setup-20261029'
TAGS = [{'Key': 'Application', 'Value': 'ieosuia-sms-portal'},
        {'Key': 'Purpose', 'Value': 'consent-based-campaigns'},
        {'Key': 'Setup', 'Value': 'ses-20261029'}]


def stamp():
    return datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')


def record(name, value):
    OUT.mkdir(parents=True, exist_ok=True)
    with (OUT / name).open('x', encoding='utf-8') as handle:
        json.dump(value, handle, indent=2)


class Setup:
    def __init__(self):
        self.session = botocore.session.get_session()
        self.options = Config(connect_timeout=10, read_timeout=15, retries={'max_attempts': 2})
        self.ses = self.session.create_client('sesv2', region_name=REGION, config=self.options)
        identity = self.session.create_client('sts', region_name=REGION, config=self.options).get_caller_identity()
        self.account = identity['Account']
        # The owner explicitly confirmed this masked account. A full identity hash
        # is pinned in the private baseline before any mutation/resumption.
        if not self.account.endswith('0326'):
            raise RuntimeError('The authenticated account is not the owner-confirmed account.')
        self.account_hash = hashlib.sha256(self.account.encode()).hexdigest()
        self.identity_hash = hashlib.sha256(identity['Arn'].encode()).hexdigest()

    def raw(self, operation, **params):
        # Older installed botocore omits new PricingAttributes / SigningHostedZone
        # response fields. Capture the signed HTTPS response before model filtering.
        captured = {}
        event = 'after-call.sesv2.' + operation
        def capture(http_response, **kwargs):
            if http_response.status_code < 300:
                captured.update(json.loads(http_response.content or b'{}'))
        self.ses.meta.events.register(event, capture)
        try:
            method = {'GetAccount': self.ses.get_account,
                      'GetEmailIdentity': self.ses.get_email_identity,
                      'CreateEmailIdentity': self.ses.create_email_identity}[operation]
            parsed = method(**params)
            return captured or parsed
        finally:
            self.ses.meta.events.unregister(event, capture)

    def optional(self, method, **params):
        try:
            return method(**params)
        except ClientError as error:
            if error.response['Error']['Code'] == 'NotFoundException':
                return None
            raise

    def account_summary(self):
        data = self.raw('GetAccount')
        return {'account': '********' + self.account[-4:], 'region': REGION,
                'pricing': data.get('PricingAttributes'),
                'production_access': data.get('ProductionAccessEnabled'),
                'enforcement_status': data.get('EnforcementStatus'),
                'quota': data.get('SendQuota'),
                'account_sending_enabled': data.get('SendingEnabled')}

    def identity_count(self):
        identities, token = [], None
        while True:
            page = self.ses.list_email_identities(**({'NextToken': token} if token else {}))
            identities.extend(page.get('EmailIdentities', []))
            next_token = page.get('NextToken')
            if not next_token:
                break
            if next_token == token:
                raise RuntimeError('Repeated SES pagination token; inspect the identity listing.')
            token = next_token
        return len(identities), sum(item['IdentityName'] != DOMAIN for item in identities)

    def owned(self, kind, name):
        arn = f'arn:aws:ses:{REGION}:{self.account}:{kind}/{name}'
        tags = self.ses.list_tags_for_resource(ResourceArn=arn).get('Tags', [])
        if not all(tag in tags for tag in TAGS):
            raise RuntimeError('An existing target lacks this setup ownership marker; inspect it manually.')

    def inspect(self):
        result = self.account_summary()
        identity = self.optional(lambda **p: self.raw('GetEmailIdentity', **p), EmailIdentity=DOMAIN)
        configuration = self.optional(self.ses.get_configuration_set, ConfigurationSetName=CONFIGURATION_SET)
        result.update({'identity': DOMAIN, 'identity_exists': identity is not None,
                       'configuration_set_exists': configuration is not None,
                       'configuration_set_sending': configuration.get('SendingOptions') if configuration else None,
                       'verified_for_sending': identity.get('VerifiedForSendingStatus') if identity else False,
                       'dkim_status': identity.get('DkimAttributes', {}).get('Status') if identity else None,
                       'mail_from': identity.get('MailFromAttributes') if identity else None,
                       'read_only': True, 'sms_submitted': False, 'emails_sent': False})
        record('inspect-' + stamp() + '.json', result)
        print(json.dumps(result, indent=2))

    def set_lowest_pricing(self):
        before = self.account_summary()
        pricing = before.get('pricing') or {}
        if pricing.get('CurrentPlan') == 'NONE' and pricing.get('NextPlan') in (None, 'NONE'):
            return
        _, unrelated = self.identity_count()
        if unrelated or float((before.get('quota') or {}).get('SentLast24Hours', 0)):
            raise RuntimeError('Existing SES workload detected; do not change its account-wide pricing automatically.')
        if pricing.get('CurrentPlan') not in ('ESSENTIALS', 'NONE'):
            raise RuntimeError('Unexpected paid/unknown pricing plan. Confirm it before changing the account.')
        # Current public SES REST API, unavailable in the older installed SDK.
        url = f'https://email.{REGION}.amazonaws.com/v2/email/account/pricing-attributes'
        request = AWSRequest(method='PUT', url=url, data=b'{"Plan":"NONE"}',
                             headers={'Content-Type': 'application/json'})
        credentials = self.session.get_credentials().get_frozen_credentials()
        SigV4Auth(credentials, 'ses', REGION).add_auth(request)
        transport = URLLib3Session(timeout=20, verify=True)
        try:
            response = transport.send(request.prepare())
            if response.status_code != 200:
                raise RuntimeError('SES pricing update was not accepted; HTTP ' + str(response.status_code))
        finally:
            transport.close()
        after = self.account_summary()
        record('pricing-update-' + stamp() + '.json', {'before': before, 'after': after, 'requested_plan': 'NONE'})
        if (after.get('pricing') or {}).get('CurrentPlan') != 'NONE':
            raise RuntimeError('A-la-carte pricing is not yet active; inspect the scheduled plan before proceeding.')

    def prepare(self):
        baseline_path = OUT / 'baseline.json'
        if baseline_path.exists():
            baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
            if baseline['account_sha256'] != self.account_hash or baseline['identity_sha256'] != self.identity_hash:
                raise RuntimeError('Authenticated AWS identity differs from the immutable setup baseline.')
        else:
            identities, unrelated = self.identity_count()
            if identities:
                raise RuntimeError('Existing SES identities need review before first setup.')
            record('baseline.json', {'account_sha256': self.account_hash, 'identity_sha256': self.identity_hash,
                                    'account': self.account_summary(), 'existing_identities': identities,
                                    'domain': DOMAIN, 'configuration_set': CONFIGURATION_SET,
                                    'recorded_at_utc': datetime.now(timezone.utc).isoformat()})
        self.set_lowest_pricing()
        config = self.optional(self.ses.get_configuration_set, ConfigurationSetName=CONFIGURATION_SET)
        if config is None:
            self.ses.create_configuration_set(ConfigurationSetName=CONFIGURATION_SET,
                SendingOptions={'SendingEnabled': False},
                ReputationOptions={'ReputationMetricsEnabled': False},
                SuppressionOptions={'SuppressedReasons': ['BOUNCE', 'COMPLAINT']}, Tags=TAGS)
            record('created-configuration-' + stamp() + '.json', {'name': CONFIGURATION_SET, 'sending_enabled': False})
        else:
            self.owned('configuration-set', CONFIGURATION_SET)
            if config.get('SendingOptions', {}).get('SendingEnabled') is not False:
                raise RuntimeError('The setup configuration set is no longer paused; do not overwrite it.')
        identity = self.optional(lambda **p: self.raw('GetEmailIdentity', **p), EmailIdentity=DOMAIN)
        if identity is None:
            self.raw('CreateEmailIdentity', EmailIdentity=DOMAIN, ConfigurationSetName=CONFIGURATION_SET,
                     DkimSigningAttributes={'NextSigningKeyLength': 'RSA_2048_BIT'}, Tags=TAGS)
            record('created-identity-' + stamp() + '.json', {'identity': DOMAIN, 'default_configuration_set': CONFIGURATION_SET})
        else:
            self.owned('identity', DOMAIN)
            if identity.get('ConfigurationSetName') != CONFIGURATION_SET:
                raise RuntimeError('Identity default configuration has changed; inspect it before proceeding.')
            existing_mail_from = identity.get('MailFromAttributes', {})
            if existing_mail_from.get('MailFromDomain') and (
                    existing_mail_from['MailFromDomain'] != MAIL_FROM or
                    existing_mail_from.get('BehaviorOnMxFailure') != 'REJECT_MESSAGE'):
                raise RuntimeError('The existing custom MAIL FROM configuration changed; do not overwrite it.')
        if identity is None or not identity.get('MailFromAttributes', {}).get('MailFromDomain'):
            self.ses.put_email_identity_mail_from_attributes(EmailIdentity=DOMAIN, MailFromDomain=MAIL_FROM,
                                                            BehaviorOnMxFailure='REJECT_MESSAGE')
        identity = self.raw('GetEmailIdentity', EmailIdentity=DOMAIN)
        config = self.ses.get_configuration_set(ConfigurationSetName=CONFIGURATION_SET)
        if config.get('SendingOptions', {}).get('SendingEnabled') is not False:
            raise RuntimeError('Paused sending was not verified.')
        dkim = identity.get('DkimAttributes', {})
        zone = dkim.get('SigningHostedZone')
        tokens = dkim.get('Tokens', [])
        if not zone or len(tokens) != 3:
            raise RuntimeError('SES signing-zone metadata is unavailable; retrieve exact DNS records from its console.')
        records = [{'type': 'CNAME', 'name': token + '._domainkey.' + DOMAIN,
                    'value': token + '.' + zone, 'ttl': 300} for token in tokens]
        records.extend([
            {'type': 'MX', 'name': MAIL_FROM, 'value': '10 feedback-smtp.' + REGION + '.amazonses.com', 'ttl': 300},
            {'type': 'TXT', 'name': MAIL_FROM, 'value': 'v=spf1 include:amazonses.com ~all', 'ttl': 300},
            {'type': 'TXT', 'name': '_dmarc.' + DOMAIN, 'value': 'v=DMARC1; p=none; adkim=s; aspf=r;', 'ttl': 300},
        ])
        record('dns-records-' + stamp() + '.json', {'domain': DOMAIN, 'region': REGION, 'records': records,
               'warning': 'Add only these new subdomain records after checking authoritative DNS. Never replace root MX/SPF/DMARC.'})
        result = {'prepared': True, 'region': REGION, 'account': '********' + self.account[-4:],
                  'pricing': self.account_summary()['pricing'], 'identity': DOMAIN,
                  'default_configuration_set': CONFIGURATION_SET, 'campaign_sending_paused': True,
                  'dkim_status': dkim.get('Status'), 'dns_records_prepared': len(records),
                  'dns_changes': False, 'smtp_credentials_created': False, 'paid_ip_created': False,
                  'production_access_requested': False, 'emails_sent': False,
                  'portal_deployed': False, 'at_utc': datetime.now(timezone.utc).isoformat()}
        record('preparation-' + stamp() + '.json', result)
        print(json.dumps(result, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['inspect', 'prepare'])
    args = parser.parse_args()
    try:
        getattr(Setup(), args.mode)()
    except ClientError as error:
        # Do not dump SDK responses, access keys, Authorization headers or DNS secrets.
        print(json.dumps({'ok': False, 'aws_error': error.response['Error']['Code'], 'emails_sent': False}))
        raise SystemExit(1)
