# webauthn_challenges

WebAuthn pending challenges (registration/authentication) stored server-side.

## Columns
| Column | Type | Null | Default | Description |
| --- | --- | --- | --- | --- |
| id | BIGINT | NO |  | Surrogate primary key. |
| rp_id | VARCHAR(255) | NO |  | Relying Party ID (domain). |
| challenge_hash | CHAR(64) | NO |  | SHA-256 hash of the challenge (hex). |
| metadata | mysql: JSON / postgres: JSONB | NO |  | Stored challenge metadata as JSON (type, subject, allowed credentials). |
| expires_at | mysql: DATETIME(6) / postgres: TIMESTAMPTZ(6) | NO |  | Expiration timestamp (UTC). |
| created_at | mysql: DATETIME(6) / postgres: TIMESTAMPTZ(6) | NO | CURRENT_TIMESTAMP(6) | Creation timestamp (UTC). |

## Engine Details

### mysql

Unique keys:
| Name | Columns |
| --- | --- |
| ux_webauthn_challenge | rp_id, challenge_hash |

Indexes:
| Name | Columns | SQL |
| --- | --- | --- |
| idx_webauthn_challenge_expires | expires_at | INDEX idx_webauthn_challenge_expires (expires_at) |
| ux_webauthn_challenge | rp_id,challenge_hash | UNIQUE KEY ux_webauthn_challenge (rp_id, challenge_hash) |

### postgres

Unique keys:
| Name | Columns |
| --- | --- |
| ux_webauthn_challenge | rp_id, challenge_hash |

Indexes:
| Name | Columns | SQL |
| --- | --- | --- |
| idx_webauthn_challenge_expires | expires_at | CREATE INDEX IF NOT EXISTS idx_webauthn_challenge_expires ON webauthn_challenges (expires_at) |
| ux_webauthn_challenge | rp_id,challenge_hash | CONSTRAINT ux_webauthn_challenge UNIQUE (rp_id, challenge_hash) |

## Engine differences

## Views
| View | Engine | Flags | File |
| --- | --- | --- | --- |
| vw_webauthn_challenges | mysql | algorithm=MERGE, security=INVOKER | [../schema/040_views.mysql.sql](../schema/040_views.mysql.sql) |
| vw_webauthn_challenges | postgres |  | [../schema/040_views.postgres.sql](../schema/040_views.postgres.sql) |
