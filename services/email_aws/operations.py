"""A bounded, read-only monitor for durable campaigns needing attention. No send or accounting access."""
import os
import time
from store import DynamoStore
from telemetry import emit


def inspect_state(store, now):
    refs = store.query('ACTIVE', limit=51)
    held = stalled = unknown = 0
    for _, _, ref in refs[:50]:
        cid = ref.get('campaign')
        if not isinstance(cid, str) or len(cid) != 32 or any(c not in '0123456789abcdef' for c in cid):
            unknown += 1; continue
        _, campaign = store.get(('C#' + cid, 'META'))
        if not campaign:
            unknown += 1; continue
        if campaign['state'] == 'held':
            held += 1
        queued = campaign.get('queued_at') or ref.get('queued_at')
        if type(queued) is not int or queued < 1:
            unknown += 1
        elif now - queued > 1800 and (campaign['state'] not in ['completed', 'cancelled'] or campaign.get('reserved_cents', 0) > 0):
            stalled += 1
    return {'active_checked': min(50, len(refs)), 'held': held, 'stalled': stalled, 'unknown': unknown, 'overflow': int(len(refs) > 50)}


def probe(event, context):
    import boto3
    from botocore.config import Config
    store = DynamoStore(boto3.client('dynamodb', config=Config(connect_timeout=3, read_timeout=5, retries={'total_max_attempts': 2})), os.environ['STATE_TABLE'])
    result = inspect_state(store, time.time())
    emit('probe', {'OperationalFailures': sum(result[k] for k in ['held', 'stalled', 'unknown', 'overflow'])})
    return result
