-- Auto-generated from schema-views-postgres.yaml
-- engine: postgres
-- table:  webauthn_challenges

-- Contract view for [webauthn_challenges]
-- Exposes metadata for challenge validation; includes digest helper for debug/audit.
CREATE OR REPLACE VIEW vw_webauthn_challenges AS
SELECT
  id,
  rp_id,
  challenge_hash,
  metadata,
  expires_at,
  created_at,
  UPPER(encode(digest(metadata::text,'sha256'),'hex')) AS metadata_hex
FROM webauthn_challenges;
