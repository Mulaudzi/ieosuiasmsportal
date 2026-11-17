"""Bounded, private, single-write uploads. Only scanned, inspected, immutable bytes can be mailed."""
import base64
import hashlib
import io
import re
import secrets
import time
import warnings
from protocol import Denied, public_url
from presentation import asset_id

MiB = 1024 * 1024
LIMITS = {'attachment_count': 3, 'attachment_bytes': 2 * MiB, 'attachment_total_bytes': 5 * MiB, 'logo_bytes': 256 * 1024, 'hero_bytes': 512 * 1024, 'mime_bytes': 8 * MiB, 'campaign_delivery_bytes': 100 * MiB, 'daily_delivery_bytes': 500 * MiB, 'upload_daily_user_bytes': 25 * MiB, 'upload_daily_global_bytes': 250 * MiB, 'retention_days': 90}
TYPES = {'application/pdf': '.pdf', 'image/png': '.png', 'image/jpeg': '.jpg'}


def inspect_file(raw, mime, purpose):
    if mime not in TYPES or purpose not in ['attachment', 'logo', 'hero']:
        raise Denied('Only PDF, PNG and JPEG files are supported')
    cap = LIMITS['attachment_bytes'] if purpose == 'attachment' else LIMITS[purpose + '_bytes']
    if not raw or len(raw) > cap or purpose != 'attachment' and mime == 'application/pdf':
        raise Denied('This file exceeds its type or size limit')
    if mime.startswith('image/'):
        from PIL import Image
        Image.MAX_IMAGE_PIXELS = 4_000_000
        with warnings.catch_warnings():
            warnings.simplefilter('error', Image.DecompressionBombWarning)
            try:
                image = Image.open(io.BytesIO(raw))
                expected = 'PNG' if mime == 'image/png' else 'JPEG'
                if image.format != expected or getattr(image, 'n_frames', 1) != 1 or image.width > 2400 or image.height > 2400 or image.width * image.height > 4_000_000:
                    raise ValueError('Unsupported image')
                image.verify()
                image = Image.open(io.BytesIO(raw)); image.load()
                # Re-encode pixels only, removing metadata, embedded payloads and trailing bytes.
                clean = io.BytesIO()
                image.convert('RGBA' if expected == 'PNG' else 'RGB').save(clean, format=expected, **({'optimize': True} if expected == 'PNG' else {'quality': 90}))
                value = clean.getvalue()
                if len(value) > cap:
                    raise ValueError('Normalized image is too large')
                return value, {'format': expected, 'width': image.width, 'height': image.height, 'review_required': True, 'text': ''}
            except Exception:
                raise Denied('Use a valid, static PNG or JPEG within the image dimensions/size limits') from None
    from pypdf import PdfReader, apply_configuration
    from pypdf.generic import IndirectObject, DictionaryObject, ArrayObject
    if not raw.startswith(b'%PDF-') or not raw.rstrip().endswith(b'%%EOF'):
        raise Denied('The file content does not match a complete PDF')
    try:
        with apply_configuration(maximum_declared_stream_length=8 * MiB, zlib_maximum_output_length=8 * MiB, array_based_stream_maximum_output_length=8 * MiB, lzw_maximum_output_length=8 * MiB, run_length_maximum_output_length=8 * MiB, stream_decoding_work_maximum_length=16 * MiB, stream_parsing_work_maximum_count=100000, dictionary_maximum_element_count=5000, array_maximum_element_count=5000, disable_legacy_handling=True):
            reader = PdfReader(io.BytesIO(raw), strict=True)
            if reader.is_encrypted or not 1 <= len(reader.pages) <= 30:
                raise ValueError('Encrypted or oversized document')
            forbidden = {'/AA', '/OpenAction', '/JS', '/JavaScript', '/Launch', '/EmbeddedFiles', '/EmbeddedFile', '/EF', '/Filespec', '/FileAttachment', '/RichMedia', '/XFA', '/AcroForm', '/Encrypt', '/SubmitForm', '/GoToR', '/GoToE', '/ImportData', '/Rendition', '/Sound', '/Movie', '/3D'}
            seen, todo, visited, links = set(), [reader.trailer], 0, []
            while todo:
                value = todo.pop(); visited += 1
                if visited > 20000:
                    raise ValueError('Complex document')
                if isinstance(value, IndirectObject):
                    key = (value.idnum, value.generation)
                    if key in seen:
                        continue
                    seen.add(key); todo.append(value.get_object())
                elif isinstance(value, DictionaryObject):
                    if set(value) & forbidden or any(str(value.get(k, '')) in forbidden for k in ['/S', '/Type', '/Subtype']):
                        raise ValueError('Active document')
                    if '/URI' in value:
                        links.append(public_url(str(value['/URI'])))
                        if len(links) > 30:
                            raise ValueError('Too many document links')
                    todo.extend(value.values())
                elif isinstance(value, ArrayObject):
                    todo.extend(value)
            strings = []
            for page in reader.pages:
                stream = page.get_contents()
                if stream and len(stream.get_data()) > 8 * MiB:
                    raise ValueError('Large decompressed document')
                strings.append(page.extract_text() or '')
                if sum(len(v) for v in strings) > 60000:
                    raise ValueError('Document text limit')
            readable = '\n'.join(strings + links)
            bounded = readable.encode()[:12000].decode(errors='ignore')
            return raw, {'format': 'PDF', 'pages': len(reader.pages), 'review_required': True, 'text': bounded, 'links': links, 'text_extraction_complete': bool(readable.strip()) and bounded == readable}
    except Exception:
        raise Denied('Use an unencrypted PDF of at most 30 pages without scripts, forms or embedded files') from None


class S3Files:
    def __init__(self, client, bucket):
        self.client, self.bucket = client, bucket

    def ticket(self, record):
        checksum = base64.b64encode(bytes.fromhex(record['sha256'])).decode()
        params = {'Bucket': self.bucket, 'Key': record['key'], 'ContentLength': record['bytes'], 'ContentType': record['mime'], 'ChecksumSHA256': checksum, 'IfNoneMatch': '*'}
        return {'url': self.client.generate_presigned_url('put_object', Params=params, ExpiresIn=300), 'method': 'PUT', 'headers': {'Content-Type': record['mime'], 'x-amz-checksum-sha256': checksum, 'If-None-Match': '*'}}

    def raw(self, record, version):
        response = self.client.get_object(Bucket=self.bucket, Key=record['key'], VersionId=version)
        if response['ContentLength'] != record['bytes'] or response['ContentType'] != record['mime']:
            raise Denied('Uploaded content does not match the reserved file')
        raw = response['Body'].read(record['bytes'] + 1)
        if len(raw) != record['bytes'] or hashlib.sha256(raw).hexdigest() != record['sha256']:
            raise Denied('File checksum mismatch')
        return raw

    def clean(self, record, raw):
        from botocore.exceptions import ClientError
        key = 'clean/' + str(record['owner']) + '/' + record['id']
        sha = hashlib.sha256(raw).hexdigest()
        try:
            response = self.client.put_object(Bucket=self.bucket, Key=key, Body=raw, ContentType=record['mime'], Metadata={'sha256': sha}, IfNoneMatch='*')
        except ClientError as error:
            if error.response['Error']['Code'] != 'PreconditionFailed':
                raise
            response = self.client.head_object(Bucket=self.bucket, Key=key)
            if response.get('Metadata', {}).get('sha256') != sha:
                raise Denied('Immutable cleaned file conflict', 409)
        return {'clean_key': key, 'clean_version': response['VersionId'], 'clean_sha256': sha, 'clean_bytes': len(raw)}

    def download(self, value):
        disposition = 'inline' if value['mime'].startswith('image/') else 'attachment'
        return self.client.generate_presigned_url('get_object', Params={'Bucket': self.bucket, 'Key': value['clean_key'], 'VersionId': value['clean_version'], 'ResponseContentDisposition': disposition + '; filename="' + value['name'] + '"'}, ExpiresIn=300)

    def bytes(self, value):
        response = self.client.get_object(Bucket=self.bucket, Key=value['clean_key'], VersionId=value['clean_version'])
        if response['ContentLength'] != value['clean_bytes']:
            raise Denied('Saved file size changed', 409)
        raw = response['Body'].read(value['clean_bytes'] + 1)
        if len(raw) != value['clean_bytes'] or hashlib.sha256(raw).hexdigest() != value['clean_sha256']:
            raise Denied('Saved file checksum changed', 409)
        return raw


class Assets:
    def __init__(self, store, files, settings, clock=time.time):
        self.store, self.files, self.settings, self.clock = store, files, settings, clock

    def limits(self):
        return {'enabled': bool(self.files and self.settings.get('uploads_enabled')), **LIMITS, 'types': list(TYPES)}

    def get(self, owner, id):
        _, value = self.store.get(('A#' + str(owner), 'FILE#' + asset_id(id)))
        if not value or value['owner'] != owner:
            raise Denied('File not found', 404)
        return value

    def view(self, value):
        result = {k: value[k] for k in ['id', 'owner', 'name', 'mime', 'purpose', 'bytes', 'state', 'expires_at']}
        result['inspection'] = {k: v for k, v in value.get('inspection', {}).items() if k != 'text'}
        result['download_url'] = self.files.download(value) if self.files and value['state'] == 'ready' and value['expires_at'] > self.clock() else None
        return result

    def create(self, owner, data):
        if not self.limits()['enabled']:
            raise Denied('Private uploads await malware-scanner setup', 503)
        purpose, mime, size, sha = [data.get(k) for k in ['purpose', 'mime', 'bytes', 'sha256']]
        if purpose not in ['attachment', 'logo', 'hero'] or mime not in TYPES or type(size) is not int or size < 1 or not isinstance(sha, str) or not re.fullmatch('[a-f0-9]{64}', sha):
            raise Denied('Invalid file metadata')
        cap = LIMITS['attachment_bytes'] if purpose == 'attachment' else LIMITS[purpose + '_bytes']
        if (purpose != 'attachment' and mime == 'application/pdf') or size > cap:
            raise Denied('File type or size exceeds the initial limits')
        name = data.get('name')
        if not isinstance(name, str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9 _().-]{0,79}', name) or not name.lower().endswith((TYPES[mime], '.jpeg') if mime == 'image/jpeg' else (TYPES[mime],)):
            raise Denied('Use a simple file name matching its PDF, PNG or JPEG content type')
        manifest_key = ('A#' + str(owner), 'FILES')
        mr, manifest = self.store.get(manifest_key);manifest = manifest or {'ids': []}
        manifest['ids'] = [id for id in manifest['ids'] if (self.store.get(('A#' + str(owner), 'FILE#' + id))[1] or {}).get('expires_at', 0) > self.clock()]
        if len(manifest['ids']) >= 50:
            raise Denied('At most 50 active files are allowed per account', 429)
        id = secrets.token_hex(16);now = int(self.clock());day = now // 86400
        manifest['ids'].insert(0, id)
        writes = {manifest_key: (mr, manifest)}
        for key, cap in [(('A#' + str(owner), 'UPLOAD-BUDGET'), LIMITS['upload_daily_user_bytes']), (('SYSTEM', 'UPLOAD-BUDGET'), LIMITS['upload_daily_global_bytes'])]:
            rev, budget = self.store.get(key);budget = budget if budget and budget['day'] == day else {'day': day, 'bytes': 0}
            if budget['bytes'] + size > cap:
                raise Denied('Daily upload allowance reached; unused tickets also count', 429)
            budget['bytes'] += size;writes[key] = (rev, budget)
        value = {'id': id, 'owner': owner, 'key': 'quarantine/' + str(owner) + '/' + id, 'purpose': purpose, 'mime': mime, 'name': name, 'bytes': size, 'sha256': sha, 'state': 'pending_scan', 'expires_at': now + 7 * 86400, '_ttl': now + 8 * 86400}
        writes[('A#' + str(owner), 'FILE#' + id)] = (0, value);self.store.commit(writes)
        return {'file': self.view(value), 'upload': self.files.ticket(value)}

    def snapshot(self, owner, design, ids):
        if not isinstance(ids, list) or len(ids) > 3:
            raise Denied('Choose at most three distinct attachments')
        ids = [asset_id(v) for v in ids]
        if len(set(ids)) != len(ids):
            raise Denied('Choose distinct attachments')
        selected = [(asset_id(v), 'attachment') for v in ids] + [(asset_id(design[k]), k) for k in ['logo', 'hero'] if design[k]]
        if not selected:
            return []
        if not self.limits()['enabled']:
            raise Denied('Files cannot be sent until private scanning is enabled', 503)
        result = []
        for id, purpose in selected:
            value = self.get(owner, id)
            if value['state'] != 'ready' or value['purpose'] != purpose or value['expires_at'] <= self.clock():
                raise Denied('Every file must pass malware/content inspection and remain available', 409)
            item = {k: value[k] for k in ['id', 'owner', 'name', 'mime', 'purpose', 'bytes', 'clean_key', 'clean_version', 'clean_sha256', 'clean_bytes', 'expires_at']}
            item['inspection'] = {k: v for k, v in value['inspection'].items() if k != 'text'}
            result.append(item)
        if sum(v['clean_bytes'] for v in result if v['purpose'] == 'attachment') > LIMITS['attachment_total_bytes']:
            raise Denied('Attachments may total at most 5 MB')
        return result

    def verify(self, manifest):
        if manifest and not self.limits()['enabled']:
            raise Denied('File sending is paused', 503)
        for value in manifest:
            current = self.get(value['owner'], value['id'])
            if current['state'] != 'ready' or current['expires_at'] <= self.clock() or any(current.get(k) != value[k] for k in ['clean_version', 'clean_sha256', 'clean_bytes']):
                raise Denied('A reviewed file is no longer eligible', 409)

    def scan(self, event):
        if event.get('source') != 'aws.guardduty' or event.get('detail-type') != 'GuardDuty Malware Protection Object Scan Result' or event.get('account') != self.settings.get('aws_account') or event.get('region') != 'eu-north-1' or event.get('resources') != [self.settings.get('scan_plan_arn')]:
            raise Denied('Unexpected malware scan source', 403)
        details = event.get('detail', {});obj = details.get('s3ObjectDetails', {})
        if obj.get('bucketName') != self.settings.get('asset_bucket'):
            raise Denied('Unexpected upload bucket', 403)
        match = re.fullmatch(r'quarantine/([1-9][0-9]{0,17})/([a-f0-9]{32})', obj.get('objectKey', ''))
        if not match or not obj.get('versionId'):
            raise Denied('Unexpected upload key/version', 403)
        owner, id = int(match[1]), match[2];key = ('A#' + str(owner), 'FILE#' + id);rev, value = self.store.get(key)
        if not value:
            return
        status = details.get('scanResultDetails', {}).get('scanResultStatus')
        if value['state'] == 'blocked' or value['state'] == 'ready' and status == 'NO_THREATS_FOUND':
            return value['state']
        value.update(scan_result=status, raw_version=obj['versionId'])
        if details.get('scanStatus') != 'COMPLETED' or status != 'NO_THREATS_FOUND':
            value.update(state='blocked', reason='Malware scan did not return a clean result')
        else:
            try:
                raw = self.files.raw(value, obj['versionId']);clean, inspection = inspect_file(raw, value['mime'], value['purpose'])
                value.update(self.files.clean(value, clean));value.update(state='ready', inspection=inspection, expires_at=int(self.clock()) + 90 * 86400, _ttl=int(self.clock()) + 91 * 86400)
            except Denied as error:
                value.update(state='blocked', reason=str(error))
        self.store.commit({key: (rev, value)})
        return value['state']
