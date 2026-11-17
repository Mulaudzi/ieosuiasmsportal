from pathlib import Path
import sys
import unittest
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[1]
sys.path.insert(0,str(ROOT/'.sms-audit.local/email-aws-tools/vendor'))
sys.path.insert(0,str(ROOT/'.sms-audit.local/email-aws-tools/test-extra'))
suite=unittest.defaultTestLoader.discover(str(HERE/'tests'))
result=unittest.TextTestRunner(verbosity=1).run(suite)
raise SystemExit(not result.wasSuccessful())
