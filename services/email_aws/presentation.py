"""Server-owned, escaped email layouts. Customer values never become arbitrary HTML/CSS."""
import html
import re
from protocol import Denied, text, public_url

CATALOG = [
    {'id': 'letter', 'name': 'Business letter', 'description': 'A simple branded message with a clear signature.'},
    {'id': 'announcement', 'name': 'Announcement', 'description': 'A prominent heading and an accent border.'},
    {'id': 'newsletter', 'name': 'Newsletter', 'description': 'A colour banner for updates and community news.'},
    {'id': 'showcase', 'name': 'Showcase', 'description': 'An optional image and one clear action button.'},
]
DEFAULT = {'layout': 'letter', 'primary': '#1763D1', 'background': '#F3F6FB', 'text': '#152238', 'logo': '', 'hero': '', 'button_label': '', 'button_url': ''}


def asset_id(value):
    if not isinstance(value, str) or not re.fullmatch('[a-f0-9]{32}', value):
        raise Denied('Choose an uploaded file owned by this account')
    return value


def luminance(value):
    channels = [int(value[i:i + 2], 16) / 255 for i in [1, 3, 5]]
    c = [v / 12.92 if v <= .04045 else ((v + .055) / 1.055) ** 2.4 for v in channels]
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]


def contrast(a, b):
    x, y = sorted([luminance(a), luminance(b)])
    return (y + .05) / (x + .05)


def design(value=None):
    if value is None:
        return dict(DEFAULT)
    if not isinstance(value, dict) or set(value) - set(DEFAULT):
        raise Denied('Choose template settings from the supported controls')
    result = {**DEFAULT, **value}
    if result['layout'] not in [t['id'] for t in CATALOG]:
        raise Denied('Unknown email layout')
    for name in ['primary', 'background', 'text']:
        if not isinstance(result[name], str) or not re.fullmatch('#[A-Fa-f0-9]{6}', result[name]):
            raise Denied('Use a six-digit colour value')
        result[name] = result[name].upper()
    if contrast(result['text'], '#FFFFFF') < 4.5:
        raise Denied('Choose a readable text colour on white (contrast at least 4.5:1)')
    for name in ['logo', 'hero']:
        if result[name]:
            asset_id(result[name])
    result['button_label'] = text(result['button_label'], 0, 60)
    result['button_url'] = text(result['button_url'], 0, 1000)
    if bool(result['button_label']) != bool(result['button_url']):
        raise Denied('Provide both the button label and its HTTPS destination')
    if result['button_url']:
        public_url(result['button_url'])
    return result


def render(subject, body, sender, settings, unsubscribe, assets=None, preview=False):
    d = design(settings)
    esc = lambda v: html.escape(str(v), quote=True)
    files = assets or []
    inline = {v['purpose']: v for v in files if v['purpose'] in ['logo', 'hero']}
    image = lambda purpose: ('<img alt="' + ('Business logo' if purpose == 'logo' else 'Campaign image') + '" src="cid:asset-' + inline[purpose]['id'] + '" width="' + ('160' if purpose == 'logo' else '552') + '" style="display:block;max-width:100%;height:auto;margin:0 0 20px" />') if purpose in inline else ''
    primary_text = '#FFFFFF' if contrast(d['primary'], '#FFFFFF') >= 4.5 else '#000000'
    heading = '<h1 style="font-size:24px;line-height:1.35;margin:0 0 22px;color:' + d['text'] + '">' + esc(subject) + '</h1>'
    banner = ''
    if d['layout'] == 'newsletter':
        banner = '<tr><td style="padding:20px 28px;background:' + d['primary'] + ';color:' + primary_text + ';font-size:18px;font-weight:bold">' + esc(sender['business_name']) + '</td></tr>'
    border = 'border-top:6px solid ' + d['primary'] + ';' if d['layout'] == 'announcement' else ''
    button = ''
    if d['button_url']:
        button = '<p style="margin:24px 0"><a href="' + esc(d['button_url']) + '" style="display:inline-block;padding:12px 22px;border-radius:6px;background:' + d['primary'] + ';color:' + primary_text + ';font-weight:bold;text-decoration:none">' + esc(d['button_label']) + '</a></p>'
    report = unsubscribe + '&report=1'
    footer = '<hr style="border:0;border-top:1px solid #D9E0EA;margin:28px 0 20px"><p style="font-size:12px;line-height:1.6;color:#475569">Sent by ' + esc(sender['business_name']) + ' via IEOSUIA.<br>' + esc(sender['postal_address']).replace('\n', '<br>') + '<br>Replies: ' + esc(sender['reply_to']) + '</p><p style="font-size:12px"><a href="' + esc(unsubscribe) + '" style="color:#164CA0">Unsubscribe</a> &nbsp;·&nbsp; <a href="' + esc(report) + '" style="color:#164CA0">Report unwanted email</a></p>'
    content = image('logo') + (image('hero') if d['layout'] == 'showcase' else '') + heading + '<div style="font-size:16px;line-height:1.7;overflow-wrap:anywhere">' + esc(body).replace('\n', '<br>') + '</div>' + (image('hero') if d['layout'] != 'showcase' else '') + button + footer
    csp = '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src data:; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">' if preview else ''
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' + csp + '<title>' + esc(subject) + '</title></head><body style="margin:0;padding:24px 12px;background:' + d['background'] + ';font-family:Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#FFFFFF;color:' + d['text'] + ';' + border + '">' + banner + '<tr><td style="padding:28px">' + content + '</td></tr></table></td></tr></table></body></html>'
