# Test strategy

`php api/tests/run-unit.php` runs pure domain tests and never opens a database or provider connection. `php api/tests/run-provider-unit.php` validates LogicSMS request construction and response/error parsing using an injected transport; it does not make a network request or send a message.

Database integration tests require a dedicated database whose name ends in `_test`. Production credentials must never be supplied. Provider tests use `APP_ENV=testing` and `SMS_GATEWAY=mock`; the mock provider refuses to run outside testing.

Obsolete PHPUnit tests that could mutate whichever database was configured have been removed. Add future integration tests only behind a dedicated `_test` database guard.
