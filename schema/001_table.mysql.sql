-- Auto-generated from schema-map-mysql.yaml (map@sha1:0D716345C0228A9FD8972A3D31574000D05317DB)
-- engine: mysql
-- table:  webauthn_challenges

CREATE TABLE IF NOT EXISTS webauthn_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rp_id VARCHAR(255) NOT NULL,
  challenge_hash CHAR(64) NOT NULL,
  metadata JSON NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY ux_webauthn_challenge (rp_id, challenge_hash),
  INDEX idx_webauthn_challenge_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
