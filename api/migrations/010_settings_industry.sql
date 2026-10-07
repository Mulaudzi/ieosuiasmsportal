-- Add only the missing organisation setting; preserves existing account data.
SET @industry_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name='industry');
SET @industry_sql = IF(@industry_exists=0,'ALTER TABLE accounts ADD COLUMN industry VARCHAR(100) NULL','SELECT 1');
PREPARE industry_statement FROM @industry_sql;
EXECUTE industry_statement;
DEALLOCATE PREPARE industry_statement;
