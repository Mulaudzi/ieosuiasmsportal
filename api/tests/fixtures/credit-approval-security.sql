CREATE TABLE credit_approval_security(id INTEGER PRIMARY KEY,password_hash TEXT,version INTEGER DEFAULT 0,updated_by INTEGER,updated_at TEXT);
INSERT INTO credit_approval_security(id,version) VALUES(1,0);
CREATE TABLE credit_approval_attempts(user_id INTEGER PRIMARY KEY,failures INTEGER DEFAULT 0,window_started INTEGER,blocked_until INTEGER DEFAULT 0);
CREATE TABLE credit_approval_events(id INTEGER PRIMARY KEY,user_id INTEGER,event TEXT,order_id INTEGER,security_version INTEGER,created_at TEXT);
