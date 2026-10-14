-- The least-privilege role the backup uses. Run once, as a superuser, against the shared server:
--   psql -U postgres -d erp -v pw="'<generated password>'" -f create-backup-role.sql   (feed the password on stdin, never in a file)
--
-- Read-only on the `erp` database only: it cannot write, cannot create objects, and has no access to any other database's
-- tables. Existing application roles and privileges are not touched.
CREATE ROLE erp_backup LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION CONNECTION LIMIT 2 PASSWORD :pw;
ALTER ROLE erp_backup SET default_transaction_read_only = on;
ALTER ROLE erp_backup SET statement_timeout = '30min';
ALTER ROLE erp_backup SET lock_timeout = '10s';

GRANT USAGE ON SCHEMA public TO erp_backup;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO erp_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO erp_backup;
-- Tables and sequences that future migrations create (the application role owns and creates them).
ALTER DEFAULT PRIVILEGES FOR ROLE erp_app IN SCHEMA public GRANT SELECT ON TABLES TO erp_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_app IN SCHEMA public GRANT SELECT ON SEQUENCES TO erp_backup;
