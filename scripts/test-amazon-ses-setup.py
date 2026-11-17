"""Offline onboarding guard tests. Never loads credentials or creates AWS clients."""
import importlib.util
import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('ses_setup', Path(__file__).with_name('setup-amazon-ses.py'))
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class SetupTests(unittest.TestCase):
    def instance(self, plan='ESSENTIALS', unrelated=0, sent=0):
        obj = object.__new__(setup.Setup)
        obj.account_summary = Mock(return_value={'pricing': {'CurrentPlan': plan, 'NextPlan': None},
                                                 'quota': {'SentLast24Hours': sent}})
        obj.identity_count = Mock(return_value=(unrelated, unrelated))
        obj.session = Mock()
        obj.session.get_credentials.return_value.get_frozen_credentials.return_value = Mock()
        return obj

    def test_already_lowest_never_writes(self):
        obj = self.instance('NONE')
        with patch.object(setup, 'URLLib3Session') as transport:
            obj.set_lowest_pricing()
            transport.assert_not_called()

    def test_old_sdk_identity_listing_follows_next_token(self):
        obj = object.__new__(setup.Setup)
        obj.ses = Mock()
        obj.ses.list_email_identities.side_effect = [
            {'EmailIdentities': [{'IdentityName': setup.DOMAIN}], 'NextToken': 'next'},
            {'EmailIdentities': [{'IdentityName': 'other.example.org'}]},
        ]
        self.assertEqual(obj.identity_count(), (2, 1))
        self.assertEqual(obj.ses.list_email_identities.call_args_list[1].kwargs, {'NextToken': 'next'})

    def test_existing_workload_blocks_plan_change(self):
        for unrelated, sent in [(1, 0), (0, 1)]:
            with self.subTest(unrelated=unrelated, sent=sent):
                obj = self.instance(unrelated=unrelated, sent=sent)
                with patch.object(setup, 'URLLib3Session') as transport:
                    with self.assertRaises(RuntimeError):
                        obj.set_lowest_pricing()
                    transport.assert_not_called()

    def test_unknown_and_paid_plans_block_automatic_downgrade(self):
        for plan in [None, 'PRO', 'ENTERPRISE']:
            obj = self.instance(plan)
            with patch.object(setup, 'URLLib3Session') as transport:
                with self.assertRaises(RuntimeError):
                    obj.set_lowest_pricing()
                transport.assert_not_called()

    def test_plan_update_is_only_none_on_fixed_tls_endpoint(self):
        obj = self.instance()
        obj.account_summary.side_effect = [{'pricing': {'CurrentPlan': 'ESSENTIALS'}, 'quota': {}},
                                           {'pricing': {'CurrentPlan': 'NONE'}}]
        with patch.object(setup, 'URLLib3Session') as transport, patch.object(setup, 'SigV4Auth') as signer, patch.object(setup, 'record') as record:
            transport.return_value.send.return_value.status_code = 200
            obj.set_lowest_pricing()
            transport.assert_called_once_with(timeout=20, verify=True)
            request = transport.return_value.send.call_args.args[0]
            self.assertEqual(request.method, 'PUT')
            self.assertEqual(request.url, 'https://email.eu-north-1.amazonaws.com/v2/email/account/pricing-attributes')
            self.assertEqual(json.loads(request.body), {'Plan': 'NONE'})
            signer.assert_called_once()
            record.assert_called_once()

    def test_unconfirmed_scheduled_change_does_not_claim_active_pricing(self):
        obj = self.instance()
        obj.account_summary.side_effect = [{'pricing': {'CurrentPlan': 'ESSENTIALS'}, 'quota': {}},
                                           {'pricing': {'CurrentPlan': 'ESSENTIALS', 'NextPlan': 'NONE'}}]
        with patch.object(setup, 'URLLib3Session') as transport, patch.object(setup, 'SigV4Auth'), patch.object(setup, 'record'):
            transport.return_value.send.return_value.status_code = 200
            with self.assertRaises(RuntimeError):
                obj.set_lowest_pricing()

    def test_full_preparation_pauses_sending_and_creates_no_keys(self):
        obj = self.instance('NONE')
        obj.account = '000000000326'
        obj.account_hash, obj.identity_hash = 'account-fixture', 'identity-fixture'
        obj.ses = Mock()
        obj.set_lowest_pricing = Mock()
        identity = {'ConfigurationSetName': setup.CONFIGURATION_SET,
                    'DkimAttributes': {'Status': 'PENDING', 'Tokens': ['a'*32, 'b'*32, 'c'*32],
                                       'SigningHostedZone': 'dkim.amazonses.com'}}
        obj.optional = Mock(return_value=None)
        obj.raw = Mock(side_effect=[{}, identity])
        obj.ses.get_configuration_set.return_value = {'SendingOptions': {'SendingEnabled': False}}
        with tempfile.TemporaryDirectory(prefix='ses-onboarding-fixture-') as directory, patch.object(setup, 'OUT', Path(directory)), redirect_stdout(io.StringIO()) as printed:
            obj.prepare()
            result = json.loads(printed.getvalue())
            self.assertTrue(result['campaign_sending_paused'])
            self.assertFalse(result['emails_sent'])
            self.assertFalse(result['smtp_credentials_created'])
            self.assertEqual(result['dns_records_prepared'], 6)
            obj.ses.create_configuration_set.assert_called_once()
            self.assertFalse(obj.ses.create_configuration_set.call_args.kwargs['SendingOptions']['SendingEnabled'])
            self.assertEqual(obj.ses.put_email_identity_mail_from_attributes.call_args.kwargs['BehaviorOnMxFailure'], 'REJECT_MESSAGE')
            obj.ses.send_email.assert_not_called()
            obj.ses.send_bulk_email.assert_not_called()
            records = list(Path(directory).glob('dns-records-*.json'))
            dns = json.loads(records[0].read_text())['records']
            self.assertTrue(all(item['name'].endswith('.campaigns.ieosuia.com') for item in dns))
            self.assertTrue((Path(directory) / 'baseline.json').exists())


if __name__ == '__main__':
    unittest.main()
