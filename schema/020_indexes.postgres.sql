-- Auto-generated from schema-map-postgres.yaml (map@sha1:621FDD3D99B768B6A8AD92061FB029414184F4B3)
-- engine: postgres
-- table:  webauthn_challenges

CREATE INDEX IF NOT EXISTS idx_webauthn_challenge_expires ON webauthn_challenges (expires_at);
