<?php

return static function (PDO $pdo): void {
    Migration::assertNoDuplicates($pdo,'wallet_transactions',['reference'],'wallet transaction reference');
    Migration::addColumn($pdo,'wallet_transactions','payos_reference','VARCHAR(255) NULL');
    Migration::addColumn($pdo,'wallet_transactions','checkout_initiated_at','DATETIME NULL');
    Migration::addIndex($pdo,'wallet_transactions','uniq_wallet_transaction_reference','`reference`',true);
    Migration::addIndex($pdo,'wallet_transactions','uniq_wallet_transaction_payos_reference','`payos_reference`',true);
};

