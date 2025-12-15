<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\WebauthnChallenges;

use BlackCat\Core\Database as Database;
use BlackCat\Database\Packages\WebauthnChallenges\Criteria;
use BlackCat\Database\Packages\WebauthnChallenges\Repository\WebauthnChallengeRepositoryInterface;
use BlackCat\Database\Packages\WebauthnChallenges\Repository\WebauthnChallengeRepository as GeneratedRepository;
use BlackCat\Database\Contracts\ContractRepository as RepoContract;

/**
 * Umbrella/facade for tests and tooling - keeps a stable FQN.
 */
final class Repository
{
  private WebauthnChallengeRepositoryInterface $repo;

  public function __construct(private Database $db)
  {
      $this->repo = new GeneratedRepository($db);
  }

    public function _setRepositoryForTests(RepoContract $repo): void
    {
        foreach (['paginateBySeek','paginate','getById','getByUnique','findById','exists','count'] as $method) {
            if (!method_exists($repo, $method)) {
                throw new \InvalidArgumentException("Test repo must implement {$method}().");
            }
        }
        if (!$repo instanceof WebauthnChallengeRepositoryInterface) {
            throw new \InvalidArgumentException('Expected WebauthnChallengeRepositoryInterface');
        }
        $this->repo = $repo;
    }

    public function criteria(int|string|array|null $tenantId = null, string $tenantColumn = "tenant_id", bool $quoteIdentifiers = false): Criteria
    {
        return Criteria::fromDb($this->db, $tenantId, $tenantColumn, $quoteIdentifiers);
    }

    // --- Create/Upsert/Batch -------------------------------------------------

    public function insert(array $row): void { $this->repo->insert($row); }
    public function insertMany(array $rows): void { $this->repo->insertMany($rows); }

    public function upsert(array $row): void { $this->repo->upsert($row); }
    public function upsertByKeys(array $row, array $keys, array $updateColumns = []): void
    {
        if (method_exists($this->repo, 'upsertByKeys')) {
            $this->repo->upsertByKeys($row, $keys, $updateColumns);
            return;
        }
        $this->repo->upsert($row);
    }
    public function upsertMany(array $rows): void
    {
        if (method_exists($this->repo, 'upsertMany')) { $this->repo->upsertMany($rows); }
        else { foreach ($rows as $r) { $this->upsert((array)$r); } }
    }

    public function upsertRevive(array $row): void
    {
        if (method_exists($this->repo, 'upsertRevive')) { $this->repo->upsertRevive($row); }
        else { $this->repo->upsert($row); }
    }
    public function upsertByKeysRevive(array $row, array $keys, array $updateColumns = []): void
    {
        if (method_exists($this->repo, 'upsertByKeysRevive')) {
            $this->repo->upsertByKeysRevive($row, $keys, $updateColumns);
            return;
        }
        $this->upsertByKeys($row, $keys, $updateColumns);
    }
    public function upsertManyRevive(array $rows): void
    {
        if (method_exists($this->repo, 'upsertManyRevive')) { $this->repo->upsertManyRevive($rows); }
        else { foreach ($rows as $r) { $this->upsertRevive((array)$r); } }
    }

    // --- Update/Delete/Restore ----------------------------------------------
    public function updateById(int|string|array $id, array $row): int { return $this->repo->updateById($id, $row); }
    public function deleteById(int|string|array $id): int { return $this->repo->deleteById($id); }
    public function restoreById(int|string|array $id): int { return $this->repo->restoreById($id); }

    // --- Read/Exists/Count ---------------------------------------------------
    public function findById(int|string|array $id): ?array { return $this->repo->findById($id); }
    public function exists(string $whereSql = '1=1', array $params = []): bool { return $this->repo->exists($whereSql ?: '1=1', $params); }
    public function count(string $whereSql = '1=1', array $params = []): int { return $this->repo->count($whereSql ?: '1=1', $params); }
    public function findAllByIds(array $ids): array { return $this->repo->findAllByIds($ids); }
    public function getByUnique(array $keys, bool $asDto = false): array|\BlackCat\Database\Packages\WebauthnChallenges\Dto\WebauthnChallengeDto|null { return $this->repo->getByUnique($keys, $asDto); }
    public function getById(int|string|array $id, bool $asDto = false): array|\BlackCat\Database\Packages\WebauthnChallenges\Dto\WebauthnChallengeDto|null { return $this->repo->getById($id, $asDto); }

    // --- Pagination / Lock ---------------------------------------------------
    public function paginate(object $c): array {
        if (!$c instanceof Criteria) {
            throw new \InvalidArgumentException('Expected ' . Criteria::class);
        }
        return $this->repo->paginate($c);
    }
    public function paginateBySeek(object $c, array $order, ?array $cursor, int $limit): array
    {
        if (!$c instanceof Criteria) {
            throw new \InvalidArgumentException('Expected ' . Criteria::class);
        }
        return $this->repo->paginateBySeek($c, $order, $cursor, $limit);
    }

    public function lockById(int|string|array $id, string $mode = 'wait', string $strength = 'update'): ?array {
        $mode = in_array($mode, ['wait','nowait','skip_locked'], true) ? $mode : 'wait';
        $strength = in_array($strength, ['update','share'], true) ? $strength : 'update';
        return $this->repo->lockById($id, $mode, $strength);
    }
}
