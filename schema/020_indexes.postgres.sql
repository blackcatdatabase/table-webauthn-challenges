-- Auto-generated from schema-map-postgres.yaml (map@sha1:FAEA49A5D5F8FAAD9F850D0F430ED451C5C1D707)
-- engine: postgres
-- table:  webauthn_challenges

CREATE INDEX IF NOT EXISTS idx_webauthn_challenge_expires ON webauthn_challenges (expires_at);
