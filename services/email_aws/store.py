"""Optimistic document transactions; SQLite fixtures and AWS DynamoDB use the same contract."""
import copy
import json
import sqlite3
from contextlib import closing


class Conflict(Exception):
    pass


class SqliteStore:
    def __init__(self, path):
        self.path = str(path)
        with closing(self.connect()) as db:
            db.execute('CREATE TABLE IF NOT EXISTS documents(p TEXT,k TEXT,rev INTEGER,data TEXT,PRIMARY KEY(p,k))')

    def connect(self):
        db = sqlite3.connect(self.path, timeout=10, isolation_level=None)
        db.execute('PRAGMA busy_timeout=10000')
        return db

    def get(self, key):
        with closing(self.connect()) as db:
            row = db.execute('SELECT rev,data FROM documents WHERE p=? AND k=?', key).fetchone()
        return (row[0], json.loads(row[1])) if row else (0, None)

    def query(self, partition, prefix='', limit=100):
        with closing(self.connect()) as db:
            rows = db.execute('SELECT k,rev,data FROM documents WHERE p=? ORDER BY k DESC', [partition]).fetchall()
        return [(k, rev, json.loads(data)) for k, rev, data in rows if k.startswith(prefix)][:limit]

    def commit(self, writes, checks=None):
        checks = checks or {}
        assert not set(writes).intersection(checks)
        db = self.connect()
        try:
            db.execute('BEGIN IMMEDIATE')
            for key, expected in {**checks, **{k: v[0] for k, v in writes.items()}}.items():
                row = db.execute('SELECT rev FROM documents WHERE p=? AND k=?', key).fetchone()
                if (row[0] if row else 0) != expected:
                    raise Conflict()
            for key, (rev, value) in writes.items():
                if value is None:
                    db.execute('DELETE FROM documents WHERE p=? AND k=?', key)
                else:
                    db.execute('INSERT INTO documents VALUES(?,?,?,?) ON CONFLICT(p,k) DO UPDATE SET rev=excluded.rev,data=excluded.data', [*key, rev + 1, json.dumps(value, separators=(',', ':'))])
            db.commit()
        except BaseException:
            db.rollback()
            raise
        finally:
            db.close()


class DynamoStore:
    def __init__(self, client, table):
        self.client, self.table = client, table

    @staticmethod
    def keys(key):
        return {'p': {'S': key[0]}, 'k': {'S': key[1]}}

    def get(self, key):
        row = self.client.get_item(TableName=self.table, Key=self.keys(key), ConsistentRead=True).get('Item')
        return (int(row['rev']['N']), json.loads(row['data']['S'])) if row else (0, None)

    def query(self, partition, prefix='', limit=100):
        rows, cursor = [], None
        while len(rows) < limit:
            args = {'TableName': self.table, 'KeyConditionExpression': 'p=:p', 'ExpressionAttributeValues': {':p': {'S': partition}}, 'ConsistentRead': True, 'ScanIndexForward': False, 'Limit': limit - len(rows)}
            if prefix:
                args['KeyConditionExpression'] += ' AND begins_with(k,:k)'
                args['ExpressionAttributeValues'][':k'] = {'S': prefix}
            if cursor:
                args['ExclusiveStartKey'] = cursor
            reply = self.client.query(**args)
            rows.extend((row['k']['S'], int(row['rev']['N']), json.loads(row['data']['S'])) for row in reply.get('Items', []))
            cursor = reply.get('LastEvaluatedKey')
            if not cursor:
                break
        return rows

    def commit(self, writes, checks=None):
        checks = checks or {}
        assert not set(writes).intersection(checks)
        operations = []
        for key, rev in {**checks, **{k: v[0] for k, v in writes.items()}}.items():
            condition = {'ConditionExpression': 'rev=:r', 'ExpressionAttributeValues': {':r': {'N': str(rev)}}} if rev else {'ConditionExpression': 'attribute_not_exists(p)'}
            if key in writes:
                value = writes[key][1]
                if value is None:
                    operations.append({'Delete': {'TableName': self.table, 'Key': self.keys(key), **condition}})
                    continue
                encoded = json.dumps(value, separators=(',', ':'), ensure_ascii=True)
                if len(encoded.encode()) > 350000:
                    raise ValueError('Document exceeds service size limit')
                item = {**self.keys(key), 'rev': {'N': str(rev + 1)}, 'data': {'S': encoded}}
                if '_ttl' in value:
                    item['expires'] = {'N': str(int(value['_ttl']))}
                operations.append({'Put': {'TableName': self.table, 'Item': item, **condition}})
            else:
                operations.append({'ConditionCheck': {'TableName': self.table, 'Key': self.keys(key), **condition}})
        try:
            self.client.transact_write_items(TransactItems=operations)
        except self.client.exceptions.TransactionCanceledException as error:
            reasons = error.response.get('CancellationReasons', [])
            if any(r.get('Code') == 'ConditionalCheckFailed' for r in reasons):
                raise Conflict() from None
            raise


def changed(document):
    return copy.deepcopy(document)
