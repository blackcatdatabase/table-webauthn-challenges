<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\WebauthnChallenges;

use BlackCat\Database\Support\Criteria as BaseCriteria;
use BlackCat\Core\Database;

/**
 * Per-repo Criteria - thin layer on top of the central BlackCat\Database\Support\Criteria.
 *
 * All the "hard" logic (dialect, LIKE/ILIKE, NULLS LAST, tenancy, seek, join params,
 * andWhere()/bind() compatibility, etc.) lives in BaseCriteria. Here we only declare whitelists
 * and per-repo limits plus an optional fromDb() factory.
 */
final class Criteria extends BaseCriteria
{
    /** Hard clamp perPage to [1..maxPerPage] for this repo. */
    public function perPage(): int
    {
        $pp = (int) parent::perPage();
        $pp = max(1, $pp);
        return min($pp, $this->maxPerPage());
    }

    /** Columns that are safe to use inside WHERE filters. */
    protected function filterable(): array
    {
        return [ 'id', 'rp_id', 'challenge_hash', 'metadata', 'expires_at', 'created_at' ];
    }

    /** Columns used for full-text LIKE/ILIKE searches. */
    protected function searchable(): array
    {
        return [ 'rp_id', 'challenge_hash' ];
    }

    /** Columns allowed in ORDER BY (falls back to filterable() when empty). */
    protected function sortable(): array
    {
        return [ 'id', 'expires_at', 'created_at' ];
    }

    /**
     * Whitelist of joinable entities (for safe ->join() usage):
     * e.g., [ 'orders' => 'j0', 'users' => 'j1' ]
     */
    protected function joinable(): array
    {
        /** @var array<string,string> */
        return [];
    }

    /** Default page size for this repository. */
    protected function defaultPerPage(): int
    {
        return 50;
    }

    /** Maximum allowed page size. */
    protected function maxPerPage(): int
    {
        return 500;
    }

    /**
     * QoL factory: detect dialect based on the PDO driver and optionally apply a tenancy filter.
     */
    public static function fromDb(
        Database $db,
        int|string|array|null $tenantId = null,
        string $tenantColumn = "tenant_id",
        bool $quoteIdentifiers = false
    ): static {
        $c = new static();

        $c->setDialectFromDatabase($db);
        if ($quoteIdentifiers) { $c->enableIdentifierQuoting(true); }
        if (
            $tenantId !== null
            && $tenantColumn !== ''
            && \method_exists(\BlackCat\Database\Packages\WebauthnChallenges\Definitions::class, 'hasTenant')
            && \BlackCat\Database\Packages\WebauthnChallenges\Definitions::hasTenant()
        ) {
            $c->tenant($tenantId, $tenantColumn);
        }

        if (\method_exists(\BlackCat\Database\Packages\WebauthnChallenges\Definitions::class, 'softDeleteColumn')) {
            $soft = \BlackCat\Database\Packages\WebauthnChallenges\Definitions::softDeleteColumn();
            if ($soft) { $c->softDelete($soft); }
        }
        return $c;
    }

    // --- Generated criteria helpers (per table) ---
    
    public function byId(int|string $id): static {
        return $this->where('id', '=', $id);
    }
    public function byIds(array $ids): static {
        if (!$ids) return $this->whereRaw('1=0');
        return $this->where('id', 'IN', array_values($ids));
    }
    public function createdBetween(?\DateTimeInterface $from, ?\DateTimeInterface $to): static {
        return $this->between('created_at', $from, $to);
    }

}
