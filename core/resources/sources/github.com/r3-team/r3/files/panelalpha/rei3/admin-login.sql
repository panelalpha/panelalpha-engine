-- Run by the admin-login one-shot (psql -v u=<username> -v p=<password>):
-- the engine's login on REI3's built-in admin, which a new database gets as
-- admin / admin (db_initialize: login.CreateAdmin("admin", "admin")). Only
-- while it still has that password, so a password changed in REI3 is never
-- touched. The hash is REI3's own: hex sha256 of salt + password, salt 32
-- characters (login.GenerateSaltHash; checked in login_auth.User).
--
-- Columns pinned: instance.login (name, salt char(32), hash char(64), ldap_id).
\set ON_ERROR_STOP on
UPDATE instance.login AS l
SET    name = :'u',
       salt = s.salt,
       hash = encode(sha256(convert_to(s.salt || :'p', 'UTF8')), 'hex')
FROM   (SELECT replace(gen_random_uuid()::text, '-', '') AS salt) AS s
WHERE  l.name = 'admin'
AND    l.ldap_id IS NULL
AND    l.hash = encode(sha256(convert_to(l.salt || 'admin', 'UTF8')), 'hex');
