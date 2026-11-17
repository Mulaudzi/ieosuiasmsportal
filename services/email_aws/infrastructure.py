"""Dedicated pay-per-use infrastructure. New deployments cannot enable dispatch or schedules."""
import json

PREFIX = 'ieosuia-email'
PARAMETERS = {name: '/ieosuia/email/v1/' + name for name in ['portal', 'accounting', 'preferences']}


def ref(name):
    return {'Ref': name}


def arn(name):
    return {'Fn::GetAtt': [name, 'Arn']}


def sub(value):
    return {'Fn::Sub': value}


def template():
    resources = {}
    retain = {'DeletionPolicy': 'Retain', 'UpdateReplacePolicy': 'Retain'}
    resources['State'] = {'Type': 'AWS::DynamoDB::Table', **retain, 'Properties': {'TableName': PREFIX + '-state-v1', 'BillingMode': 'PAY_PER_REQUEST', 'AttributeDefinitions': [{'AttributeName': n, 'AttributeType': 'S'} for n in ['p', 'k']], 'KeySchema': [{'AttributeName': 'p', 'KeyType': 'HASH'}, {'AttributeName': 'k', 'KeyType': 'RANGE'}], 'SSESpecification': {'SSEEnabled': True}, 'PointInTimeRecoverySpecification': {'PointInTimeRecoveryEnabled': True}, 'TimeToLiveSpecification': {'AttributeName': 'expires', 'Enabled': True}, 'OnDemandThroughput': {'MaxReadRequestUnits': 1000, 'MaxWriteRequestUnits': 1000}, 'Tags': [{'Key': 'Application', 'Value': PREFIX}]}}
    resources['FeedbackTopic'] = {'Type': 'AWS::SNS::Topic', **retain, 'Properties': {'TopicName': 'ieosuia-portal-email-events', 'SignatureVersion': '2'}}
    resources['TopicPolicy'] = {'Type': 'AWS::SNS::TopicPolicy', 'Properties': {'Topics': [ref('FeedbackTopic')], 'PolicyDocument': {'Version': '2012-10-17', 'Statement': [{'Effect': 'Allow', 'Principal': {'Service': 'ses.amazonaws.com'}, 'Action': 'sns:Publish', 'Resource': ref('FeedbackTopic'), 'Condition': {'StringEquals': {'AWS:SourceAccount': ref('AWS::AccountId')}, 'ArnEquals': {'AWS:SourceArn': sub('arn:${AWS::Partition}:ses:${AWS::Region}:${AWS::AccountId}:configuration-set/ieosuia-portal-campaigns')}}}]}}}
    resources['DeadLetters'] = {'Type': 'AWS::SQS::Queue', **retain, 'Properties': {'QueueName': PREFIX + '-feedback-dead-v1', 'SqsManagedSseEnabled': True, 'MessageRetentionPeriod': 1209600}}
    resources['FeedbackQueue'] = {'Type': 'AWS::SQS::Queue', **retain, 'Properties': {'QueueName': PREFIX + '-feedback-v1', 'SqsManagedSseEnabled': True, 'VisibilityTimeout': 180, 'MessageRetentionPeriod': 1209600, 'RedrivePolicy': {'deadLetterTargetArn': arn('DeadLetters'), 'maxReceiveCount': 5}}}
    resources['QueuePolicy'] = {'Type': 'AWS::SQS::QueuePolicy', 'Properties': {'Queues': [ref('FeedbackQueue')], 'PolicyDocument': {'Version': '2012-10-17', 'Statement': [{'Effect': 'Allow', 'Principal': {'Service': 'sns.amazonaws.com'}, 'Action': 'sqs:SendMessage', 'Resource': arn('FeedbackQueue'), 'Condition': {'ArnEquals': {'aws:SourceArn': ref('FeedbackTopic')}, 'StringEquals': {'aws:SourceAccount': ref('AWS::AccountId')}}}, {'Effect': 'Deny', 'Principal': '*', 'Action': 'sqs:SendMessage', 'Resource': arn('FeedbackQueue'), 'Condition': {'StringNotEquals': {'aws:PrincipalServiceName': 'sns.amazonaws.com'}}}]}}}
    resources['Subscription'] = {'Type': 'AWS::SNS::Subscription', 'DependsOn': 'QueuePolicy', 'Properties': {'Protocol': 'sqs', 'Endpoint': arn('FeedbackQueue'), 'TopicArn': ref('FeedbackTopic'), 'RawMessageDelivery': False}}
    resources['SesEvents'] = {'Type': 'AWS::SES::ConfigurationSetEventDestination', 'DependsOn': ['TopicPolicy', 'Subscription'], 'Properties': {'ConfigurationSetName': 'ieosuia-portal-campaigns', 'EventDestination': {'Name': 'ieosuia-aws-campaign-events-v1', 'Enabled': True, 'MatchingEventTypes': ['SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'], 'SnsDestination': {'TopicARN': ref('FeedbackTopic')}}}}
    common = {'ASSET_BUCKET': sub('ieosuia-email-files-${AWS::AccountId}-${AWS::Region}'), 'UPLOADS_ENABLED': ref('EnableUploads'), 'STATE_TABLE': ref('State'), 'AWS_ACCOUNT_ID': ref('AWS::AccountId'), 'TOPIC_ARN': ref('FeedbackTopic'), 'PUBLIC_URL': ref('PublicUrl'), 'DISPATCH_ENABLED': 'false', 'FEEDBACK_VERIFIED': ref('FeedbackAccepted'), 'ISOLATION_VERIFIED': {'Fn::If': ['ReservedIsolation', 'true', 'false']}, 'DAILY_ATTEMPT_LIMIT': '100', 'PREFERENCE_SECRET': PARAMETERS['preferences']}
    for name, handler, secret_names, memory, timeout in [('Api', 'api', ['portal', 'preferences'], 256, 60), ('Worker', 'worker', ['accounting', 'preferences'], 256, 60), ('Feedback', 'feedback', ['accounting', 'preferences'], 256, 30)]:
        function_name = PREFIX + '-' + name.lower() + '-v1'
        statements = [{'Effect': 'Allow', 'Action': ['dynamodb:GetItem', 'dynamodb:Query'], 'Resource': arn('State')}, {'Effect': 'Allow', 'Action': ['dynamodb:PutItem', 'dynamodb:DeleteItem', 'dynamodb:ConditionCheckItem'], 'Resource': arn('State'), 'Condition': {'StringEquals': {'dynamodb:EnclosingOperation': 'TransactWriteItems'}}}, {'Effect': 'Allow', 'Action': 'ssm:GetParameter', 'Resource': [sub('arn:${AWS::Partition}:ssm:${AWS::Region}:${AWS::AccountId}:parameter' + PARAMETERS[s]) for s in secret_names]}, {'Effect': 'Allow', 'Action': ['logs:CreateLogStream', 'logs:PutLogEvents'], 'Resource': sub('arn:${AWS::Partition}:logs:${AWS::Region}:${AWS::AccountId}:log-group:/aws/lambda/' + function_name + ':*')}]
        statements.append({'Effect': 'Allow', 'Action': 'kms:Decrypt', 'Resource': '*', 'Condition': {'StringEquals': {'kms:ViaService': sub('ssm.${AWS::Region}.amazonaws.com'), 'kms:EncryptionContext:PARAMETER_ARN': [sub('arn:${AWS::Partition}:ssm:${AWS::Region}:${AWS::AccountId}:parameter' + PARAMETERS[s]) for s in secret_names]}}})
        if name in ['Api', 'Worker']:
            statements.append({'Effect': 'Allow', 'Action': ['ses:GetAccount', 'ses:GetEmailIdentity', 'ses:GetConfigurationSet', 'ses:GetConfigurationSetEventDestinations'], 'Resource': '*'})
        if name == 'Worker':
            statements.append({'Effect': 'Allow', 'Action': 'ses:SendEmail', 'Resource': [sub('arn:${AWS::Partition}:ses:${AWS::Region}:${AWS::AccountId}:identity/campaigns.ieosuia.com'), sub('arn:${AWS::Partition}:ses:${AWS::Region}:${AWS::AccountId}:configuration-set/ieosuia-portal-campaigns')], 'Condition': {'StringEquals': {'ses:FromAddress': 'messages@campaigns.ieosuia.com'}}})
        if name == 'Feedback':
            statements.append({'Effect': 'Allow', 'Action': ['sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:GetQueueAttributes'], 'Resource': arn('FeedbackQueue')})
        resources[name + 'Role'] = {'Type': 'AWS::IAM::Role', 'Properties': {'AssumeRolePolicyDocument': {'Version': '2012-10-17', 'Statement': [{'Effect': 'Allow', 'Principal': {'Service': 'lambda.amazonaws.com'}, 'Action': 'sts:AssumeRole'}]}, 'Policies': [{'PolicyName': 'OnlyDedicatedCampaignResources', 'PolicyDocument': {'Version': '2012-10-17', 'Statement': statements}}]}}
        resources[name + 'Logs'] = {'Type': 'AWS::Logs::LogGroup', **retain, 'Properties': {'LogGroupName': '/aws/lambda/' + function_name, 'RetentionInDays': 14}}
        environment = {**common}
        if 'portal' in secret_names:
            environment['PORTAL_SECRET'] = PARAMETERS['portal']
        if 'accounting' in secret_names:
            environment['ACCOUNTING_SECRET'] = PARAMETERS['accounting']
        if name == 'Feedback':
            environment['FEEDBACK_QUEUE_ARN'] = arn('FeedbackQueue')
        resources[name + 'Function'] = {'Type': 'AWS::Lambda::Function', 'DependsOn': name + 'Logs', 'Properties': {'FunctionName': function_name, 'Runtime': 'python3.13', 'Handler': 'handler.' + handler, 'Role': arn(name + 'Role'), 'Code': {'S3Bucket': ref('CodeBucket'), 'S3Key': ref('CodeKey')}, 'MemorySize': memory, 'Timeout': timeout, 'ReservedConcurrentExecutions': {'Fn::If': ['ReservedIsolation', 2 if name != 'Worker' else 1, ref('AWS::NoValue')]}, 'Environment': {'Variables': environment}}}
    resources['ApiUrl'] = {'Type': 'AWS::Lambda::Url', 'Properties': {'TargetFunctionArn': arn('ApiFunction'), 'AuthType': 'NONE'}}
    resources['ApiUrlPermission'] = {'Type': 'AWS::Lambda::Permission', 'Properties': {'FunctionName': ref('ApiFunction'), 'Action': 'lambda:InvokeFunctionUrl', 'Principal': '*', 'FunctionUrlAuthType': 'NONE'}}
    resources['ApiInvokePermission'] = {'Type': 'AWS::Lambda::Permission', 'Properties': {'FunctionName': ref('ApiFunction'), 'Action': 'lambda:InvokeFunction', 'Principal': '*', 'InvokedViaFunctionUrl': True}}
    resources['FeedbackMapping'] = {'Type': 'AWS::Lambda::EventSourceMapping', 'Properties': {'FunctionName': ref('FeedbackFunction'), 'EventSourceArn': arn('FeedbackQueue'), 'BatchSize': 5, 'FunctionResponseTypes': ['ReportBatchItemFailures'], 'ScalingConfig': {'MaximumConcurrency': 2}, 'Enabled': True}}
    resources['WorkerSchedule'] = {'Type': 'AWS::Events::Rule', 'Properties': {'Name': PREFIX + '-worker-v1', 'ScheduleExpression': 'rate(1 minute)', 'State': 'DISABLED', 'Targets': [{'Arn': arn('WorkerFunction'), 'Id': 'CampaignWorker'}]}}
    resources['SchedulePermission'] = {'Type': 'AWS::Lambda::Permission', 'Properties': {'FunctionName': ref('WorkerFunction'), 'Action': 'lambda:InvokeFunction', 'Principal': 'events.amazonaws.com', 'SourceArn': arn('WorkerSchedule'), 'SourceAccount': ref('AWS::AccountId')}}
    resources['DeadLetterAlarm'] = {'Type': 'AWS::CloudWatch::Alarm', 'Properties': {'AlarmDescription': 'Inspect retained feedback failures; never replay email submission.', 'Namespace': 'AWS/SQS', 'MetricName': 'ApproximateNumberOfMessagesVisible', 'Dimensions': [{'Name': 'QueueName', 'Value': {'Fn::GetAtt': ['DeadLetters', 'QueueName']}}], 'Statistic': 'Maximum', 'Period': 300, 'EvaluationPeriods': 1, 'Threshold': 0, 'ComparisonOperator': 'GreaterThanThreshold', 'TreatMissingData': 'notBreaching'}}
    return {'AWSTemplateFormatVersion': '2010-09-09', 'Description': 'IEOSUIA isolated consent-based campaign service; dispatch and schedule initially disabled; target 10000/day, pilot 100/day.', 'Parameters': {'CodeBucket': {'Type': 'String'}, 'CodeKey': {'Type': 'String'}, 'PublicUrl': {'Type': 'String', 'Default': 'https://disabled.invalid', 'AllowedPattern': 'https://[a-z0-9.-]+'}, 'EnableUploads': {'Type': 'String', 'Default': 'false', 'AllowedValues': ['false', 'true']}, 'FeedbackAccepted': {'Type': 'String', 'Default': 'false', 'AllowedValues': ['false', 'true']}, 'ReserveCapacity': {'Type': 'String', 'Default': 'false', 'AllowedValues': ['false', 'true']}}, 'Conditions': {'ReservedIsolation': {'Fn::Equals': [ref('ReserveCapacity'), 'true']}}, 'Resources': resources, 'Outputs': {'PublicUrl': {'Value': {'Fn::GetAtt': ['ApiUrl', 'FunctionUrl']}}, 'StateTable': {'Value': ref('State')}, 'FeedbackTopic': {'Value': ref('FeedbackTopic')}, 'FeedbackQueueArn': {'Value': arn('FeedbackQueue')}}}


if __name__ == '__main__':
    print(json.dumps(template(), indent=2))
