-- Runs once, when the database volume is first created (docker-entrypoint-initdb.d).
-- The PHPUnit suite uses the `comtso_test` database (doctrine dbname_suffix in the test env).
CREATE DATABASE IF NOT EXISTS comtso_test CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON `comtso%`.* TO 'comtso'@'%';
