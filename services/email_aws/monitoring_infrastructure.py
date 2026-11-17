"""Dedicated operational notifications and bounded read-only monitoring. Never permits email campaigns."""
from infrastructure import ref, arn, sub


def contact_template():
    retain = {'DeletionPolicy': 'Retain', 'UpdateReplacePolicy': 'Retain'}
    policy = lambda statements: {'Version': '2012-10-17', 'Statement': statements}
    resources = {
        'Alerts': {'Type': 'AWS::SNS::Topic', **retain, 'Properties': {'TopicName': 'ieosuia-email-operations-v1', 'DisplayName': 'IEOSUIA email operations', 'SignatureVersion': '2'}},
        'AlertsPolicy': {'Type': 'AWS::SNS::TopicPolicy', 'Properties': {'Topics': [ref('Alerts')], 'PolicyDocument': policy([{'Effect': 'Allow', 'Principal': {'Service': 'cloudwatch.amazonaws.com'}, 'Action': 'sns:Publish', 'Resource': ref('Alerts'), 'Condition': {'StringEquals': {'aws:SourceAccount': ref('AWS::AccountId')}, 'ArnLike': {'aws:SourceArn': sub('arn:${AWS::Partition}:cloudwatch:${AWS::Region}:${AWS::AccountId}:alarm:ieosuia-email-*')}}}])}},
        'EmailSubscription': {'Type': 'AWS::SNS::Subscription', 'Properties': {'TopicArn': ref('Alerts'), 'Protocol': 'email', 'Endpoint': ref('AlertEmail')}},
        'Receipts': {'Type': 'AWS::SQS::Queue', **retain, 'Properties': {'QueueName': 'ieosuia-email-alert-receipts-v1', 'SqsManagedSseEnabled': True, 'MessageRetentionPeriod': 1209600}},
        'ReceiptPolicy': {'Type': 'AWS::SQS::QueuePolicy', 'Properties': {'Queues': [ref('Receipts')], 'PolicyDocument': policy([{'Effect': 'Allow', 'Principal': {'Service': 'sns.amazonaws.com'}, 'Action': 'sqs:SendMessage', 'Resource': arn('Receipts'), 'Condition': {'ArnEquals': {'aws:SourceArn': ref('Alerts')}, 'StringEquals': {'aws:SourceAccount': ref('AWS::AccountId')}}}])}},
        'ReceiptSubscription': {'Type': 'AWS::SNS::Subscription', 'DependsOn': 'ReceiptPolicy', 'Properties': {'TopicArn': ref('Alerts'), 'Protocol': 'sqs', 'Endpoint': arn('Receipts'), 'RawMessageDelivery': False}},
    }
    return {'AWSTemplateFormatVersion': '2010-09-09', 'Description': 'IEOSUIA email operational alert contact and durable receipts; no campaign sending.', 'Parameters': {'AlertEmail': {'Type': 'String', 'AllowedPattern': '[A-Za-z0-9_.+%-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,63}'}}, 'Resources': resources, 'Outputs': {'AlertTopic': {'Value': ref('Alerts')}, 'Receipts': {'Value': ref('Receipts')}}}


def template():
    t = contact_template(); r = t['Resources']
    t['Description'] = 'IEOSUIA email failure alerts, bounded read-only probe and confirmed operational contact. No send permission.'
    t['Parameters'].update({k: {'Type': 'String'} for k in ['CodeBucket', 'CodeKey', 'StateTable']})
    state_arn = sub('arn:${AWS::Partition}:dynamodb:${AWS::Region}:${AWS::AccountId}:table/${StateTable}')
    r['ProbeRole'] = {'Type': 'AWS::IAM::Role', 'Properties': {'AssumeRolePolicyDocument': {'Version': '2012-10-17', 'Statement': [{'Effect': 'Allow', 'Principal': {'Service': 'lambda.amazonaws.com'}, 'Action': 'sts:AssumeRole'}]}, 'Policies': [{'PolicyName': 'ReadOnlyActiveCampaignMetadata', 'PolicyDocument': {'Version': '2012-10-17', 'Statement': [{'Effect': 'Allow', 'Action': ['dynamodb:Query', 'dynamodb:GetItem'], 'Resource': state_arn, 'Condition': {'ForAllValues:StringLike': {'dynamodb:LeadingKeys': ['ACTIVE', 'C#*']}}}, {'Effect': 'Allow', 'Action': ['logs:CreateLogStream', 'logs:PutLogEvents'], 'Resource': sub('arn:${AWS::Partition}:logs:${AWS::Region}:${AWS::AccountId}:log-group:/aws/lambda/ieosuia-email-operations-v1:*')}]}}]}}
    r['ProbeLogs'] = {'Type': 'AWS::Logs::LogGroup', 'DeletionPolicy': 'Retain', 'UpdateReplacePolicy': 'Retain', 'Properties': {'LogGroupName': '/aws/lambda/ieosuia-email-operations-v1', 'RetentionInDays': 14}}
    r['Probe'] = {'Type': 'AWS::Lambda::Function', 'DependsOn': 'ProbeLogs', 'Properties': {'FunctionName': 'ieosuia-email-operations-v1', 'Runtime': 'python3.13', 'Handler': 'operations.probe', 'Role': arn('ProbeRole'), 'Code': {'S3Bucket': ref('CodeBucket'), 'S3Key': ref('CodeKey')}, 'MemorySize': 256, 'Timeout': 30, 'Environment': {'Variables': {'STATE_TABLE': ref('StateTable')}}}}
    r['ProbeSchedule'] = {'Type': 'AWS::Events::Rule', 'Properties': {'Name': 'ieosuia-email-operations-v1', 'ScheduleExpression': 'rate(5 minutes)', 'State': 'ENABLED', 'Targets': [{'Arn': arn('Probe'), 'Id': 'ReadOnlyCampaignCheck', 'RetryPolicy': {'MaximumRetryAttempts': 1, 'MaximumEventAgeInSeconds': 300}}]}}
    r['ProbePermission'] = {'Type': 'AWS::Lambda::Permission', 'Properties': {'FunctionName': ref('Probe'), 'Action': 'lambda:InvokeFunction', 'Principal': 'events.amazonaws.com', 'SourceArn': arn('ProbeSchedule'), 'SourceAccount': ref('AWS::AccountId')}}
    def alarm(name, namespace, metric, dimensions, description, threshold=0, period=60, evaluations=1, statistic='Sum'):
        r[name] = {'Type': 'AWS::CloudWatch::Alarm', 'Properties': {'AlarmName': 'ieosuia-email-' + name.lower() + '-v1', 'AlarmDescription': description, 'Namespace': namespace, 'MetricName': metric, 'Dimensions': [{'Name': k, 'Value': v} for k, v in dimensions.items()], 'Statistic': statistic, 'Period': period, 'EvaluationPeriods': evaluations, 'Threshold': threshold, 'ComparisonOperator': 'GreaterThanThreshold', 'TreatMissingData': 'notBreaching', 'ActionsEnabled': True, 'AlarmActions': [ref('Alerts')]}}
    for suffix, queue in [('FeedbackDead', 'ieosuia-email-feedback-dead-v1'), ('FileDead', 'ieosuia-email-file-scan-dead-v1')]:
        alarm(suffix, 'AWS/SQS', 'ApproximateNumberOfMessagesVisible', {'QueueName': queue}, 'Retained failure needs inspection. Never resend campaigns or delete unknown failures.', statistic='Maximum')
    alarm('FeedbackAge', 'AWS/SQS', 'ApproximateAgeOfOldestMessage', {'QueueName': 'ieosuia-email-feedback-v1'}, 'Feedback is delayed. Inspect mapping, function and queue before increasing sends.', threshold=300, evaluations=2, statistic='Maximum')
    for component in ['api', 'worker', 'feedback', 'file-scan', 'probe']:
        function = 'ieosuia-email-' + ('operations' if component == 'probe' else component) + '-v1'
        suffix = component.replace('-', '').title()
        alarm(suffix + 'Errors', 'AWS/Lambda', 'Errors', {'FunctionName': function}, 'Function execution failed. Inspect private sanitized logs and retained failures.')
        alarm(suffix + 'Throttles', 'AWS/Lambda', 'Throttles', {'FunctionName': function}, 'Capacity is throttled. Keep send limits and isolation gates intact.')
        alarm(suffix + 'Attention', 'IEOSUIA/Email', 'OperationalFailures', {'Component': component}, 'Operational attention reported. Held or accepted-pending mail must be reconciled without resending.')
    for kind, metric in [('Bounce', 'FeedbackBounceEvents'), ('Complaint', 'FeedbackComplaintEvents'), ('Reject', 'FeedbackRejectEvents')]:
        alarm(kind + 'Events', 'IEOSUIA/Email', metric, {'Component': 'feedback'}, 'Pilot ' + kind.lower() + ' event. Check sender, consent and suppression. Controlled SES simulator tests are recorded separately.', period=300)
    alarm('BlockedFiles', 'IEOSUIA/Email', 'FileBlockedEvents', {'Component': 'file-scan'}, 'Upload was blocked by malware or content inspection. Review private quarantine metadata; never distribute or approve blocked bytes.', period=300)
    alarm('SetupCanary', 'IEOSUIA/EmailMonitor', 'SetupCanary', {'Service': 'ieosuia-email'}, 'SETUP TEST ONLY. Verifies CloudWatch to SNS and the confirmed operational email. No customer campaign or wallet mutation.')
    t['Outputs'].update(ProbeFunction={'Value': ref('Probe')}, SetupAlarm={'Value': ref('SetupCanary')})
    return t
