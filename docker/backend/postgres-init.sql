-- Runs automatically when the postgres_dev_data volume is first initialised (empty).
-- POSTGRES_DB creates 'erp'; this script creates the test database alongside it.
-- If the volume already exists, this file is NOT re-executed by postgres.

CREATE DATABASE erp_testing;
GRANT ALL PRIVILEGES ON DATABASE erp_testing TO erp;
