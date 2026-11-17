"""Explainable risk signals, never a promise of spam detection or inbox placement."""
import re
from urllib.parse import urlsplit

VERSION = 'email-content-v1'
SHORTENERS = {'bit.ly', 'tinyurl.com', 't.co', 'is.gd', 'cutt.ly', 'rb.gy', 'shorturl.at'}


def inspect(subject, body, button_url='', files=None):
    files = files or []
    value = subject + '\n' + body + '\n' + button_url
    findings = []
    def flag(code, severity, message):
        if not any(v['code'] == code for v in findings):
            findings.append({'code': code, 'severity': severity, 'message': message})
    if re.search('[\u200b\u200e\u200f\u202a-\u202e\u2066-\u2069\ufeff]', value):
        flag('concealed_text', 'block', 'Remove invisible characters or directional overrides that could disguise content.')
    # Requests for secrets are blocked; safety advice and ordinary password-reset notices are not.
    requests = re.finditer(r'\b(?:send|share|reply with|provide|enter|confirm)\b[^.!?\n]{0,50}\b(?:your password|your otp|one.time code|recovery phrase|seed phrase|card pin)\b', value, re.I)
    if any(not re.search(r'(?:never|do not|don.t)\s*$', value[max(0, match.start() - 12):match.start()], re.I) for match in requests):
        flag('secret_request', 'block', 'Campaigns must not ask recipients to disclose passwords, PINs or recovery secrets.')
    if re.search(r'guaranteed.{0,25}(?:profit|returns)|double your money|risk.free.{0,20}(?:investment|profit)', value, re.I):
        flag('financial_promise', 'review', 'A financial-return claim needs careful independent review.')
    if re.search(r'urgent|act now|account.{0,20}(?:suspend|close)|verify.{0,20}(?:account|identity)', value, re.I):
        flag('pressure_language', 'review', 'Urgency or account-verification language can resemble phishing.')
    letters = [v for v in subject if v.isalpha()]
    if len(letters) > 12 and sum(v.isupper() for v in letters) / len(letters) > .7 or subject.count('!') > 2:
        flag('subject_style', 'review', 'Reduce excessive capitals or punctuation in the subject.')
    if re.search(r'<\s*(?:html|script|iframe|img|style|a\s)', value, re.I):
        flag('html_in_text', 'review', 'HTML is displayed as plain text; inspect this content for misleading markup.')
    urls = []
    for raw in re.findall(r'(?:https?://|www\.)[^\s<>]+', value, re.I):
        url = raw.rstrip('.,;!)');parsed = urlsplit(url);host = (parsed.hostname or '').lower()
        if url not in urls:
            urls.append(url)
        from protocol import public_url, Denied
        try:
            public_url(url)
        except Denied:
            flag('unsafe_link', 'block', 'Use a complete public HTTPS destination without credentials, private hosts or custom ports.')
        if host in SHORTENERS:
            flag('shortened_link', 'block', 'Use the full destination instead of a shortened or redirector link.')
        if host.startswith('xn--') or '.xn--' in host:
            flag('international_domain', 'review', 'Inspect the internationalized domain for brand impersonation.')
    if len(urls) > 8:
        flag('many_links', 'review', 'This message contains many different destinations.')
    for item in files:
        if item.get('inspection', {}).get('review_required'):
            flag('file_visual_review', 'review', 'Inspect every image and any document without readable text; automated text checks cannot see all visual content.')
    if files:
        flag('attachments', 'review', 'The exact attached and inline files must be opened and reviewed before approval.')
    return {'version': VERSION, 'blocked': any(v['severity'] == 'block' for v in findings), 'findings': findings, 'links': urls, 'review_required': bool(findings), 'limitations': 'Signals can miss abuse and flag legitimate content. Malware scanning, identity/consent checks and independent human review remain required.'}
