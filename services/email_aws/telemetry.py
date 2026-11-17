"""Low-cardinality CloudWatch EMF. Never accepts customer data, addresses or provider payloads."""
import json
import time

METRICS = {'OperationalFailures', 'FeedbackAcceptedEvents', 'FeedbackDeliveredEvents', 'FeedbackBounceEvents', 'FeedbackComplaintEvents', 'FeedbackRejectEvents', 'FileBlockedEvents'}
COMPONENTS = {'api', 'worker', 'feedback', 'feedback-simulator', 'file-scan', 'probe'}


def metric_record(component, values, now=None):
    if component not in COMPONENTS or not values or set(values) - METRICS or any(type(v) is not int or not 0 <= v <= 1000000 for v in values.values()):
        raise ValueError('Only bounded service counters may be logged')
    return {'_aws': {'Timestamp': int((time.time() if now is None else now) * 1000), 'CloudWatchMetrics': [{'Namespace': 'IEOSUIA/Email', 'Dimensions': [['Component']], 'Metrics': [{'Name': k, 'Unit': 'Count'} for k in values]}]}, 'Component': component, **values}


def emit(component, values):
    record = metric_record(component, values)
    try:
        print(json.dumps(record, separators=(',', ':')), flush=True)
    except (OSError, ValueError):
        return False
    return True
