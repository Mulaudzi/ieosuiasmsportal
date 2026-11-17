import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path
from telemetry import metric_record, emit
from operations import inspect_state
from store import SqliteStore
from monitoring_infrastructure import template, contact_template
from infrastructure import template as main_template
from unittest.mock import Mock, patch
import handler
from protocol import Denied


class OperationalMonitoring(unittest.TestCase):
    def test_metrics_never_accept_arbitrary_fields_or_dimensions(self):
        value=metric_record('feedback',{'OperationalFailures':1,'FeedbackComplaintEvents':2},1800000000)
        self.assertEqual(value['_aws']['CloudWatchMetrics'][0]['Dimensions'],[['Component']])
        self.assertNotIn('email',json.dumps(value));self.assertEqual(value['_aws']['Timestamp'],1800000000000)
        for component,values in [('customer@example.org',{'OperationalFailures':1}),('api',{'email':'customer@example.org'}),('api',{'OperationalFailures':True}),('api',{'OperationalFailures':-1})]:
            with self.assertRaises(ValueError):metric_record(component,values)
        out=io.StringIO()
        with contextlib.redirect_stdout(out):self.assertTrue(emit('worker',{'OperationalFailures':0}))
        self.assertEqual(json.loads(out.getvalue())['Component'],'worker')

    def test_read_only_probe_detects_held_stalled_missing_and_overflow_bounded(self):
        class Store:
            def __init__(self):self.reads=0
            def query(self,partition,limit):
                self.partition,self.limit=partition,limit
                return[(str(i),1,{'campaign':format(i,'032x'),'queued_at':100})for i in range(51)]
            def get(self,key):
                self.reads+=1;self.last=key
                return(1,None if self.reads==1 else{'state':'held'if self.reads==2 else'queued','queued_at':100,'reserved_cents':7})
        store=Store();result=inspect_state(store,2000)
        self.assertEqual(store.partition,'ACTIVE');self.assertEqual(store.limit,51);self.assertEqual(store.reads,50)
        self.assertEqual(result['unknown'],1);self.assertEqual(result['held'],1);self.assertEqual(result['stalled'],49);self.assertEqual(result['overflow'],1)
        self.assertEqual(store.last[1],'META')

    def test_probe_empty_and_young_jobs_do_not_alert(self):
        with tempfile.TemporaryDirectory()as folder:
            store=SqliteStore(Path(folder)/'state.db');self.assertEqual(sum(inspect_state(store,1800000000).values()),0)
            cid='a'*32;store.commit({('ACTIVE',cid):(0,{'campaign':cid,'queued_at':1800000000}),('C#'+cid,'META'):(0,{'state':'queued','queued_at':1800000000})})
            result=inspect_state(store,1800000100);self.assertEqual(result['active_checked'],1);self.assertEqual(result['stalled'],0)

    def test_all_operational_alarms_route_only_to_dedicated_contact(self):
        t=template();alarms=[v['Properties']for v in t['Resources'].values()if v['Type']=='AWS::CloudWatch::Alarm']
        self.assertGreaterEqual(len(alarms),20)
        for alarm in alarms:
            self.assertEqual(alarm['AlarmActions'],[{'Ref':'Alerts'}]);self.assertTrue(alarm['AlarmName'].startswith('ieosuia-email-'))
        role=t['Resources']['ProbeRole']['Properties']['Policies'][0]['PolicyDocument']['Statement']
        actions=str(role);self.assertNotIn('ses:Send',actions);self.assertNotIn('ssm:',actions);self.assertNotIn('dynamodb:Put',actions)
        self.assertEqual(t['Resources']['ProbeSchedule']['Properties']['ScheduleExpression'],'rate(5 minutes)')
        self.assertNotIn('Probe',contact_template()['Resources'])

    def test_feedback_acceptance_does_not_enable_dispatch_or_capacity(self):
        t=main_template();env=t['Resources']['WorkerFunction']['Properties']['Environment']['Variables']
        self.assertEqual(env['FEEDBACK_VERIFIED'],{'Ref':'FeedbackAccepted'});self.assertEqual(env['DISPATCH_ENABLED'],'false')
        self.assertEqual(t['Parameters']['FeedbackAccepted']['Default'],'false');self.assertEqual(t['Parameters']['ReserveCapacity']['Default'],'false')
        self.assertEqual(t['Resources']['WorkerSchedule']['Properties']['State'],'DISABLED')

    def test_real_complaints_and_simulator_events_have_separate_counters(self):
        for address,component in [('complaint@simulator.amazonses.com','feedback-simulator'),('customer@example.org','feedback'),('x@simulator.amazonses.com.attacker.org','feedback')]:
            message={'eventType':'Complaint','mail':{'destination':[address]}}
            record={'eventSource':'aws:sqs','eventSourceARN':'queue','messageId':'test-id','body':json.dumps({'Type':'Notification','TopicArn':'topic','Message':json.dumps(message)})}
            engine=Mock()
            with patch.dict(handler.os.environ,{'FEEDBACK_QUEUE_ARN':'queue','TOPIC_ARN':'topic'}),patch.object(handler,'build',return_value=(engine,None)),patch.object(handler,'emit')as metrics:
                self.assertEqual(handler.feedback({'Records':[record]},None),{'batchItemFailures':[]})
                matching=[call.args[1]for call in metrics.call_args_list if call.args[0]==component]
                self.assertEqual(matching[0]['FeedbackComplaintEvents'],1)
                if component=='feedback-simulator':self.assertEqual(metrics.call_args_list[0].args[1]['FeedbackComplaintEvents'],0)

    def test_invalid_simulator_feedback_still_alerts_as_a_processing_failure(self):
        engine=Mock();engine.feedback.side_effect=Denied('Invalid provider evidence')
        record={'eventSource':'aws:sqs','eventSourceARN':'queue','messageId':'invalid','body':json.dumps({'Type':'Notification','TopicArn':'topic','Message':json.dumps({'eventType':'Complaint','mail':{'destination':['complaint@simulator.amazonses.com']}})})}
        with patch.dict(handler.os.environ,{'FEEDBACK_QUEUE_ARN':'queue','TOPIC_ARN':'topic'}),patch.object(handler,'build',return_value=(engine,None)),patch.object(handler,'emit')as metrics:
            self.assertEqual(handler.feedback({'Records':[record]},None),{'batchItemFailures':[{'itemIdentifier':'invalid'}]})
            self.assertEqual(metrics.call_args.args[0],'feedback');self.assertEqual(metrics.call_args.args[1]['OperationalFailures'],1)

    def test_expected_daily_quota_stop_is_not_reported_as_a_failure(self):
        engine=Mock();engine.readiness.return_value={'ready':True};engine.store.query.side_effect=[[],[('job',1,{'campaign':'a'*32,'recipient':'b'*32})]];engine.dispatch_one.side_effect=Denied('Daily limit reached',429)
        context=Mock();context.get_remaining_time_in_millis.return_value=60000
        with patch.object(handler,'build',return_value=(engine,None)),patch.object(handler,'emit')as metrics:
            result=handler.worker({},context);self.assertEqual(result['quota'],1);self.assertEqual(metrics.call_args.args[1]['OperationalFailures'],0)
