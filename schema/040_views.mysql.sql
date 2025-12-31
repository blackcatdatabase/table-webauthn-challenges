-- Auto-generated from schema-views-mysql.yaml (map@sha1:9417D8642843C7C690617409574FC6783895880D)
-- engine: mysql
-- table:  webauthn_challenges

-- Contract view for [webauthn_challenges]
-- Exposes metadata for challenge validation; includes digest helper for debug/audit.
CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW vw_webauthn_challenges AS
SELECT
  id,
  rp_id,
  challenge_hash,
  metadata,
  expires_at,
  created_at,
  CAST(UPPER(SHA2(metadata, 256)) AS CHAR(64)) AS metadata_hex
FROM webauthn_challenges;
