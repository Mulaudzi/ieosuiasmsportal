<?php

return static function (PDO $pdo): void {
    Migration::addColumn($pdo, 'users', 'identity_uuid', 'CHAR(36) NULL AFTER id');
    Migration::assertNoDuplicates($pdo, 'users', ['identity_uuid'], 'central identity');
    Migration::addIndex($pdo, 'users', 'users_identity_uuid_unique', '`identity_uuid`', true);
};
