"""AWS adapters. Submission has one SDK attempt; accounting retries never invoke SES."""
import json
import ssl
import time
import urllib.error
import urllib.request
from botocore.config import Config
from protocol import Denied, encoded, sign


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class AccountingClient:
    def __init__(self, key):
        self.key = key
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def call(self, action, data):
        body = encoded({'action': action, 'data': data})
        path = '/api/email-aws-accounting.php'
        headers = sign(self.key, 'email-accounting', path, body)
        request = urllib.request.Request('https://sms.ieosuia.com' + path, data=body, headers=headers, method='POST')
        with self.opener.open(request, timeout=15) as response:
            raw = response.read(20001)
            if len(raw) > 20000:
                raise Denied('Accounting response exceeds its contract', 503)
            data = json.loads(raw)
            if data.get('success') is not True:
                raise Denied('Accounting acknowledgement unavailable', 503)
            return data


class SesProvider:
    def __init__(self, session, settings):
        self.settings = settings
        self.reader = session.client('sesv2', region_name='eu-north-1', config=Config(connect_timeout=5, read_timeout=10, retries={'total_max_attempts': 2}))
        self.sender = session.client('sesv2', region_name='eu-north-1', config=Config(connect_timeout=5, read_timeout=15, retries={'total_max_attempts': 1}))
        self.cached = None

    def health(self):
        if self.cached and time.time() - self.cached['observed_at'] < 30:
            return self.cached
        account = self.reader.get_account()
        identity = self.reader.get_email_identity(EmailIdentity='campaigns.ieosuia.com')
        config = self.reader.get_configuration_set(ConfigurationSetName=self.settings['configuration_set'])
        destinations = self.reader.get_configuration_set_event_destinations(ConfigurationSetName=self.settings['configuration_set']).get('EventDestinations', [])
        reasons = []
        if account.get('ProductionAccessEnabled') is not True:
            reasons.append('Amazon SES production access is pending.')
        if account.get('SendingEnabled') is not True or account.get('EnforcementStatus') != 'HEALTHY':
            reasons.append('SES account sending is not healthy.')
        if config.get('SendingOptions', {}).get('SendingEnabled') is not True:
            reasons.append('The dedicated SES campaign configuration is paused.')
        mail_from = identity.get('MailFromAttributes', {})
        if identity.get('VerifiedForSendingStatus') is not True or identity.get('DkimAttributes', {}).get('Status') != 'SUCCESS' or mail_from.get('MailFromDomain') != 'bounce.campaigns.ieosuia.com' or mail_from.get('MailFromDomainStatus') != 'SUCCESS' or mail_from.get('BehaviorOnMxFailure') != 'REJECT_MESSAGE':
            reasons.append('Dedicated sender authentication is not verified.')
        required = {'SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'}
        if not any(v.get('Enabled') is True and v.get('SnsDestination', {}).get('TopicArn') == self.settings['topic_arn'] and required.issubset(set(v.get('MatchingEventTypes', []))) for v in destinations):
            reasons.append('The expected SES feedback destination is not connected.')
        if not self.settings.get('feedback_verified'):
            reasons.append('Real feedback and preference acceptance checks remain pending.')
        if not self.settings.get('isolation_verified'):
            reasons.append('AWS worker concurrency isolation awaits quota acceptance.')
        quota = account.get('SendQuota', {})
        rate, maximum = float(quota.get('MaxSendRate', 0)), int(quota.get('Max24HourSend', 0))
        if rate <= 0 or maximum <= 0:
            reasons.append('SES sending quota is unavailable.')
        self.cached = {'reasons': reasons, 'observed_at': time.time(), 'max_rate': rate, 'max_24h': maximum, 'sent_24h': float(quota.get('SentLast24Hours', 0))}
        return self.cached

    def send(self, mime, recipient, tags):
        response = self.sender.send_email(FromEmailAddress=self.settings['from'], Destination={'ToAddresses': [recipient]}, Content={'Raw': {'Data': mime}}, ConfigurationSetName=self.settings['configuration_set'], EmailTags=[{'Name': k, 'Value': v} for k, v in tags.items()])
        return response['MessageId']
