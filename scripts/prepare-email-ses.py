"""SES readiness and operator artifacts only. Never creates resources, keys, subscriptions or sends."""
import argparse
import hashlib
import hmac
import json
import os
import re
import tempfile
import time
from pathlib import Path
import botocore.session
from botocore.config import Config
from botocore.exceptions import ClientError

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'.sms-audit.local/email-ses-readiness-20261030'
REGION='eu-north-1'
DOMAIN='campaigns.ieosuia.com'
CONFIG='ieosuia-portal-campaigns'
TOPIC='ieosuia-portal-email-events'
CALLBACK='https://sms.ieosuia.com/api/email-ses-feedback.php'
EVENTS={'SEND','DELIVERY','BOUNCE','COMPLAINT','REJECT'}

def artifacts(account):
    if not re.fullmatch(r'\d{12}',account):raise ValueError('Invalid account identifier')
    identity=f'arn:aws:ses:{REGION}:{account}:identity/{DOMAIN}'
    config=f'arn:aws:ses:{REGION}:{account}:configuration-set/{CONFIG}'
    topic=f'arn:aws:sns:{REGION}:{account}:{TOPIC}'
    policy={'Version':'2012-10-17','Statement':[{'Sid':'OnlyDedicatedCampaignSender','Effect':'Allow','Action':['ses:SendRawEmail'],'Resource':identity,'Condition':{'StringEquals':{'ses:FromAddress':'messages@'+DOMAIN}}}]}
    reader={'Version':'2012-10-17','Statement':[{'Sid':'ReadOnlySesHealth','Effect':'Allow','Action':['ses:GetAccount','ses:GetEmailIdentity','ses:GetConfigurationSet','ses:GetConfigurationSetEventDestinations'],'Resource':'*'},{'Sid':'InspectOnlyDedicatedFeedbackTopic','Effect':'Allow','Action':['sns:GetTopicAttributes','sns:ListSubscriptionsByTopic'],'Resource':topic},{'Sid':'InspectOnlyDedicatedSubscription','Effect':'Allow','Action':['sns:GetSubscriptionAttributes'],'Resource':topic+':*'}]}
    # SES non-sending read APIs use Resource *; no IAM mutation, message sending, keys or payments.
    publish={'Version':'2012-10-17','Statement':[{'Sid':'OnlyPinnedSesConfiguration','Effect':'Allow','Principal':{'Service':'ses.amazonaws.com'},'Action':'sns:Publish','Resource':topic,'Condition':{'StringEquals':{'AWS:SourceAccount':account},'ArnEquals':{'AWS:SourceArn':config}}}]}
    template={'AWSTemplateFormatVersion':'2010-09-09','Description':'Review-only IEOSUIA SES feedback plan. Creates SNS/SQS resources and may incur usage fees. Does not enable sending, create IAM credentials or modify DNS.','Resources':{
        'CampaignFeedbackTopic':{'Type':'AWS::SNS::Topic','DeletionPolicy':'Retain','UpdateReplacePolicy':'Retain','Properties':{'TopicName':TOPIC,'SignatureVersion':'2','Tags':[{'Key':'Application','Value':'ieosuia-sms-portal'},{'Key':'Purpose','Value':'consent-based-campaigns'}]}},
        'SesPublishPolicy':{'Type':'AWS::SNS::TopicPolicy','Properties':{'Topics':[{'Ref':'CampaignFeedbackTopic'}],'PolicyDocument':publish}},
        'FeedbackDeadLetters':{'Type':'AWS::SQS::Queue','DeletionPolicy':'Retain','UpdateReplacePolicy':'Retain','Properties':{'QueueName':'ieosuia-email-feedback-dlq','SqsManagedSseEnabled':True,'MessageRetentionPeriod':1209600}},
        'DeadLetterPolicy':{'Type':'AWS::SQS::QueuePolicy','Properties':{'Queues':[{'Ref':'FeedbackDeadLetters'}],'PolicyDocument':{'Version':'2012-10-17','Statement':[{'Effect':'Allow','Principal':{'Service':'sns.amazonaws.com'},'Action':'sqs:SendMessage','Resource':{'Fn::GetAtt':['FeedbackDeadLetters','Arn']},'Condition':{'ArnEquals':{'aws:SourceArn':topic},'StringEquals':{'aws:SourceAccount':account}}}]}}},
        'HttpsSubscription':{'Type':'AWS::SNS::Subscription','DependsOn':['SesPublishPolicy','DeadLetterPolicy'],'Properties':{'Protocol':'https','Endpoint':CALLBACK,'TopicArn':{'Ref':'CampaignFeedbackTopic'},'RawMessageDelivery':False,'RedrivePolicy':{'deadLetterTargetArn':{'Fn::GetAtt':['FeedbackDeadLetters','Arn']}},'DeliveryPolicy':{'healthyRetryPolicy':{'minDelayTarget':1,'maxDelayTarget':60,'numRetries':50,'numNoDelayRetries':3,'numMinDelayRetries':2,'numMaxDelayRetries':35,'backoffFunction':'exponential'},'throttlePolicy':{'maxReceivesPerSecond':10},'requestPolicy':{'headerContentType':'application/json'}}}},
        'SesEvents':{'Type':'AWS::SES::ConfigurationSetEventDestination','DependsOn':['SesPublishPolicy'],'Properties':{'ConfigurationSetName':CONFIG,'EventDestination':{'Name':'ieosuia-portal-feedback','Enabled':True,'MatchingEventTypes':sorted(EVENTS),'SnsDestination':{'TopicARN':{'Ref':'CampaignFeedbackTopic'}}}}}
    },'Outputs':{'TopicArn':{'Value':{'Ref':'CampaignFeedbackTopic'}},'DeadLetterQueue':{'Value':{'Ref':'FeedbackDeadLetters'}}}}
    return {'smtp-sender-policy.json':policy,'quota-reader-policy.json':reader,'feedback-infrastructure-REVIEW-ONLY.json':template}

def optional(call,**args):
    try:return call(**args)
    except ClientError as e:
        if e.response['Error']['Code'] in ['NotFoundException','NotFound','NotFoundExceptionFault']:return None
        raise

def inspect_aws():
    session=botocore.session.get_session();options=Config(connect_timeout=8,read_timeout=12,retries={'max_attempts':1})
    account=session.create_client('sts',region_name=REGION,config=options).get_caller_identity()['Account']
    if not account.endswith('0326'):raise RuntimeError('Not the owner-confirmed AWS account; stopping read-only inspection.')
    ses=session.create_client('sesv2',region_name=REGION,config=options);sns=session.create_client('sns',region_name=REGION,config=options)
    data=ses.get_account();identity=ses.get_email_identity(EmailIdentity=DOMAIN);config=ses.get_configuration_set(ConfigurationSetName=CONFIG)
    destinations=ses.get_configuration_set_event_destinations(ConfigurationSetName=CONFIG).get('EventDestinations',[])
    topic=f'arn:aws:sns:{REGION}:{account}:{TOPIC}';topic_data=optional(sns.get_topic_attributes,TopicArn=topic)
    expected_policy=artifacts(account)['feedback-infrastructure-REVIEW-ONLY.json']['Resources']['SesPublishPolicy']['Properties']['PolicyDocument']
    topic_valid=False;sub_valid=False
    if topic_data:
        attrs=topic_data['Attributes'];policy=json.loads(attrs.get('Policy','{}'))
        topic_valid=attrs.get('SignatureVersion')=='2' and policy==expected_policy
        # Fail closed on unexpected policy differences until operator review; no automatic overwrites.
        subscriptions=[];token=None
        while True:
            page=sns.list_subscriptions_by_topic(TopicArn=topic,**({'NextToken':token} if token else {}));subscriptions.extend(page.get('Subscriptions',[]))
            next_token=page.get('NextToken')
            if not next_token:break
            if next_token==token:raise RuntimeError('Unexpected SNS pagination')
            token=next_token
        for sub in subscriptions:
            if sub.get('Protocol')=='https' and sub.get('Endpoint')==CALLBACK and sub.get('SubscriptionArn','').startswith(topic+':'):
                attrs=sns.get_subscription_attributes(SubscriptionArn=sub['SubscriptionArn'])['Attributes']
                redrive=json.loads(attrs.get('RedrivePolicy','{}'))
                if attrs.get('RawMessageDelivery','false')=='false' and redrive.get('deadLetterTargetArn')==f'arn:aws:sqs:{REGION}:{account}:ieosuia-email-feedback-dlq':sub_valid=True
    feedback=topic_valid and sub_valid and any(d.get('Enabled') is True and d.get('SnsDestination',{}).get('TopicArn')==topic and EVENTS.issubset(set(d.get('MatchingEventTypes',[]))) for d in destinations)
    q=data.get('SendQuota',{})
    snapshot={'account_id':account,'region':REGION,'identity':DOMAIN,'configuration_set':CONFIG,'topic_arn':topic,'observed_at':int(time.time()),'max_per_second':q.get('MaxSendRate',0),'max_24h':q.get('Max24HourSend',0),'sent_last_24h':q.get('SentLast24Hours',0),'production_access':data.get('ProductionAccessEnabled',False),'sending_enabled':data.get('SendingEnabled',False),'configuration_enabled':config.get('SendingOptions',{}).get('SendingEnabled',False),'identity_verified':identity.get('VerifiedForSendingStatus') is True and identity.get('ConfigurationSetName')==CONFIG and identity.get('DkimAttributes',{}).get('SigningEnabled') is True and identity.get('DkimAttributes',{}).get('Status')=='SUCCESS' and identity.get('MailFromAttributes',{}).get('MailFromDomain')=='bounce.'+DOMAIN and identity.get('MailFromAttributes',{}).get('MailFromDomainStatus')=='SUCCESS' and identity.get('MailFromAttributes',{}).get('BehaviorOnMxFailure')=='REJECT_MESSAGE','feedback_destination_verified':feedback,'enforcement_status':data.get('EnforcementStatus','unknown')}
    return account,snapshot,{'feedback_topic_present':topic_data is not None,'feedback_topic_trust_verified':topic_valid,'confirmed_https_subscription_verified':sub_valid,'matching_event_destinations':len(destinations)}

def quota_envelope(snapshot,secret):
    if len(secret)<32:raise ValueError('Use a separate random quota-signing secret of at least 32 bytes')
    payload=json.dumps(snapshot,separators=(',',':'),ensure_ascii=True)
    return ('<?php exit; ?>\n'+json.dumps({'payload':payload,'signature':hmac.new(secret,payload.encode(),hashlib.sha256).hexdigest()},separators=(',',':'))).encode()

def atomic_write(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.quota-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as h:h.write(data);h.flush();os.fsync(h.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('mode',choices=['inspect','snapshot']);parser.add_argument('--output',type=Path);parser.add_argument('--secret-file',type=Path);args=parser.parse_args()
    if args.mode=='snapshot' and (not args.output or not args.secret_file):parser.error('snapshot requires --output and --secret-file; it never uploads automatically')
    account,snapshot,feedback=inspect_aws();stamp=time.strftime('%Y%m%dT%H%M%SZ',time.gmtime());OUT.mkdir(parents=True,exist_ok=True)
    public={**snapshot,'account_id':'********'+account[-4:],**feedback,'read_only_aws':True,'resources_created':False,'keys_created':False,'production_access_requested':False,'sending_enabled_by_tool':False,'emails_sent':False,'deployed':False}
    (OUT/('inspection-'+stamp+'.json')).write_text(json.dumps(public,indent=2),encoding='utf-8')
    for name,value in artifacts(account).items():(OUT/name).write_text(json.dumps(value,indent=2),encoding='utf-8')
    if args.mode=='snapshot':atomic_write(args.output,quota_envelope(snapshot,args.secret_file.read_bytes().strip()))
    print(json.dumps(public,indent=2))

if __name__=='__main__':
    try:main()
    except ClientError as e:print(json.dumps({'ok':False,'aws_error_code':e.response['Error']['Code'],'changes_made':False}));raise SystemExit(1)
