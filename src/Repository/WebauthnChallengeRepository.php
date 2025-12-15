<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\WebauthnChallenges\Repository;

use BlackCat\Core\Database as Database;              // e.g. BlackCat\Core\Database
use BlackCat\Database\Packages\WebauthnChallenges\Definitions;
use BlackCat\Database\Packages\WebauthnChallenges\Criteria;
use BlackCat\Database\Packages\WebauthnChallenges\Dto\WebauthnChallengeDto as Dto;
use BlackCat\Database\Packages\WebauthnChallenges\Mapper\WebauthnChallengeDtoMapper as RowMapper;
use BlackCat\Database\Contracts\ContractRepository as RepoContract;
use BlackCat\Database\Contracts\KeysetRepository as KeysetRepoContract;
use BlackCat\Database\Support\OrderByTools;
use BlackCat\Database\Support\SqlIdentifier as Ident;
use BlackCat\Database\Support\PkTools;
use BlackCat\Database\Support\LockMode;
use BlackCat\Database\Support\KeysetPaginator;
use BlackCat\Database\Support\UpsertBuilder;
use BlackCat\Database\Support\RepositoryHelpers;

class WebauthnChallengeRepository implements WebauthnChallengeRepositoryInterface, RepoContract, KeysetRepoContract
{
use OrderByTools, PkTools, RepositoryHelpers;

    /** @var mixed literal token for upsert keys (array or empty). */
    private mixed $tokenUpsertKeys = [];

    public function __construct(private readonly Database $db) {}

    /**
     * Optionally override the Definitions FQN â€“ trait otherwise infers it from the repository FQN.
     */
    protected function def(): string { return \BlackCat\Database\Packages\WebauthnChallenges\Definitions::class; }

    /** @return array<string,mixed>|null */
    private function mapReturnRow(array|Dto|null $row): ?array {
      return is_array($row) ? $row : null;
    }

    /** @return Dto|null */
    private function mapReturnDto(array|Dto|null $row): ?Dto {
      if ($row instanceof Dto) {
        return $row;
      }
      return is_array($row) ? RowMapper::fromRow($row) : null;
    }

    /** Resolve upsert keys (generator tokens or unique keys fallback). */
    private function resolveUpsertKeys(): array
    {
      $keys = $this->tokenUpsertKeys;
      if (!is_array($keys) || $keys === []) {
        $uqs  = Definitions::uniqueKeys();
        $keys = (array)($uqs[0] ?? []);
      }
      if (!is_array($keys) || $keys === []) {
        $keys = $this->pkColumns(Definitions::class);
      }
      return $keys;
    }

    /** @return array<string,mixed>|Dto|null */
    public function getById(int|string|array $id, bool $asDto = false): array|Dto|null {
        $row = $this->findById($id);
        return $asDto ? $this->mapReturnDto($row) : $this->mapReturnRow($row);
    }

    // --- INSERT / BULK -------------------------------------------------------

    public function insert(#[\SensitiveParameter] array $row): void {
        $row = $this->filterCols($this->normalizeInputRow($row));
        if (!$row) return;

        $cols = array_keys($row); sort($cols);
        $tbl  = Ident::qi($this->db, Definitions::table());
        $colSql = implode(',', array_map(fn($c) => Ident::q($this->db, $c), $cols));
        $phSql  = implode(',', array_map(fn($c) => ':' . $c, $cols));

        $this->db->execute("INSERT INTO {$tbl} ({$colSql}) VALUES ({$phSql})", $row);
    }

    public function insertMany(array $rows): void {
        $rows = array_values(array_filter(
            array_map(fn($r) => $this->filterCols($this->normalizeInputRow($r)), $rows),
            fn($r) => !empty($r)
        ));
        if (!$rows) return;

        // unify columns across rows (missing entries -> NULL)
        $cols = array_keys(array_reduce($rows, fn($a,$r)=>$a + array_fill_keys(array_keys($r),true), []));
        sort($cols);

        $tbl    = Ident::qi($this->db, Definitions::table());
        $colSql = implode(',', array_map(fn($c)=>Ident::q($this->db,$c), $cols));

        // conservative upper bound on parameters per INSERT
        $maxParams = 32000;
        $perRow    = max(1, count($cols));
        $chunkSize = max(1, intdiv($maxParams, $perRow));

        for ($o = 0; $o < count($rows); $o += $chunkSize) {
            $slice  = array_slice($rows, $o, $chunkSize);
            $valuesSql = [];
            $params    = [];
            $i = 0;
            foreach ($slice as $r) {
                $ph=[]; foreach ($cols as $c) { $k="p_{$o}_{$i}_{$c}"; $ph[]=":{$k}"; $params[$k]=$r[$c]??null; }
                $valuesSql[]='('.implode(',', $ph).')'; $i++;
            }
            $this->db->execute("INSERT INTO {$tbl} ({$colSql}) VALUES ".implode(',', $valuesSql), $params);
        }
    }

    // --- UPSERT (including "revive" mode) ---------------------------------------

    /** Internal helper: apply the "revive" policy (soft-delete -> NULL) before buildRow(). */
    private function applyUpsertRevivePolicy(array $row, array $updateCols, bool $revive): array
    {
        if (!$revive) {
            return [$row, $updateCols];
        }
        $soft = Definitions::softDeleteColumn();
        if ($soft) {
            // CLEAR: deleted_at = NULL on conflict.
            $row[$soft] = null;
            if (!in_array($soft, $updateCols, true)) {
                $updateCols[] = $soft;
            }
        }
        return [$row, $updateCols];
    }

    /** Upsert row – default behavior preserves soft-delete (no revive). */
    public function upsert(#[\SensitiveParameter] array $row): void
    {
        $this->doUpsert($row, false);
    }

    /** Upsert that revives soft-delete (sets deleted_at = NULL on conflict). */
    public function upsertRevive(#[\SensitiveParameter] array $row): void
    {
        $this->doUpsert($row, true);
    }

    /** Internal helper for both modes; when $revive = true it clears deleted_at on conflict. */
    private function doUpsert(array $row, bool $revive): void
    {
        $row  = $this->filterCols($this->normalizeInputRow($row));
        if (!$row) return;

        $keys = $this->resolveUpsertKeys();

        // Generated from schema-map: webauthn_challenges upsert updates metadata/expiry on (rp_id, challenge_hash).
        $updCols = [ 'metadata', 'expires_at' ];
        $updCols = array_values(array_diff($updCols, array_merge($this->pkColumns(Definitions::class), $keys)));

        // Revive policy
        [$row, $updCols] = $this->applyUpsertRevivePolicy($row, $updCols, $revive);

        [$sql, $params] = UpsertBuilder::buildRow(
            $this->db,
            Definitions::table(),
            $row,
            $keys,
            $updCols,
            Definitions::updatedAtColumn()
        );
        $this->db->execute($sql, $params);
    }

    /** Upsert by keys – default behavior keeps soft-delete. */
    public function upsertByKeys(array $row, array $keys, array $updateColumns = []): void
    {
        $this->doUpsertByKeys($row, $keys, $updateColumns, false);
    }

    /** Upsert by keys and revive soft-deleted rows (deleted_at=NULL on conflict). */
    public function upsertByKeysRevive(array $row, array $keys, array $updateColumns = []): void
    {
        $this->doUpsertByKeys($row, $keys, $updateColumns, true);
    }

    private function doUpsertByKeys(array $row, array $keys, array $updateColumns, bool $revive): void
    {
        if (!$row && !$keys) return;

        $row = $this->normalizeInputRow($row);

        // ensure key values exist in the row (fill from provided keys when missing)
        $isAssoc = $keys && array_keys($keys) !== range(0, count($keys)-1);
        $keyCols = $isAssoc ? array_keys($keys) : array_values($keys);
        if ($isAssoc) foreach ($keyCols as $kc) if (!array_key_exists($kc,$row) && array_key_exists($kc,$keys)) $row[$kc]=$keys[$kc];

        $updCols = array_values(array_diff($updateColumns, array_merge($this->pkColumns(Definitions::class), $keyCols)));

        // Revive policy
        [$row, $updCols] = $this->applyUpsertRevivePolicy($row, $updCols, $revive);

        $row  = $this->filterCols($row);
        if (!$row) return;

        [$sql, $params] = UpsertBuilder::buildByKeys(
            $this->db,
            Definitions::table(),
            $row,
            $keyCols,
            $updCols,
            Definitions::updatedAtColumn()
        );
        $this->db->execute($sql, $params);
    }

    /** Batch upsert - default (no revive). */
    public function upsertMany(array $rows): int {
        $rows = array_values(array_filter(
            array_map(fn($r) => is_array($r) ? $this->filterCols($this->normalizeInputRow($r)) : null, $rows),
            fn($r) => !empty($r)
        ));
        if (!$rows) { return 0; }

        // Optimized helper (avoids per-row upsert when definitions provide keys/columns)
        $helperKeys = $this->resolveUpsertKeys();
        if ($helperKeys && class_exists(\BlackCat\Database\Support\BulkUpsertHelper::class)) {
          $bulk = new \BlackCat\Database\Support\BulkUpsertHelper($this->db, \BlackCat\Database\Packages\WebauthnChallenges\Definitions::class);
          $bulk->upsertMany($rows, $helperKeys, []);
          return count($rows);
        }

        $n = 0;
        foreach ($rows as $r) { $this->doUpsert((array)$r, false); $n++; }
        return $n;
    }

    /** Batch upsert variant that revives soft-deleted rows. */
    public function upsertManyRevive(array $rows): int {
        $rows = array_values(array_filter($rows, 'is_array'));
        if (!$rows) { return 0; }

        // Optimized helper (revive mode)
        $helperKeys = $this->resolveUpsertKeys();
        if ($helperKeys && class_exists(\BlackCat\Database\Support\BulkUpsertHelper::class)) {
          $soft = Definitions::softDeleteColumn();
          $rows = array_values(array_filter(
              array_map(function ($r) use ($soft) {
                  if (!is_array($r)) { return null; }
                  $r = $this->normalizeInputRow($r);
                  if ($soft) { $r[$soft] = null; }
                  $r = $this->filterCols($r);
                  return $r ?: null;
              }, $rows),
              fn($r) => !empty($r)
          ));
          if (!$rows) { return 0; }
          $bulk = new \BlackCat\Database\Support\BulkUpsertHelper($this->db, \BlackCat\Database\Packages\WebauthnChallenges\Definitions::class);
          $bulk->upsertMany($rows, $helperKeys, $soft ? [$soft] : []);
          return count($rows);
        }

        $n = 0;
        foreach ($rows as $r) { $this->doUpsert((array)$r, true); $n++; }
        return $n;
    }

    // --- UPDATE / DELETE / RESTORE ------------------------------------------

    public function updateByIdWhere(int|string|array $id, #[\SensitiveParameter] array $row, array $where): int
    {
        $row = $this->filterCols($this->normalizeInputRow($row));
        if (!$row) return 0;

        $params = [];
        $pkWhere = $this->buildPkWhere('t', $this->normalizePkInput($id, $this->pkColumns(Definitions::class)), $params, 'pk_');
        $extra = $this->buildWhere($where, $params, 'w_');

        $set = [];
        foreach ($row as $k => $v) { $set[] = Ident::q($this->db, $k) . " = :set_{$k}"; $params["set_{$k}"] = $v; }

        $tbl = Ident::qi($this->db, Definitions::table());
        $sql = "UPDATE {$tbl} t SET " . implode(',', $set) . " WHERE ({$pkWhere}) AND ({$extra})";
        return $this->db->execute($sql, $params);
    }

    public function updateById(int|string|array $id, #[\SensitiveParameter] array $row): int
    {
        return $this->updateByIdWhere($id, $row, []);
    }

    public function deleteById(int|string|array $id): int
    {
        $soft = Definitions::softDeleteColumn();
        if ($soft) {
            return $this->updateById($id, [ $soft => date('Y-m-d H:i:s') ]);
        }

        $params=[]; $where = $this->buildPkWhere('', $this->normalizePkInput($id, $this->pkColumns(Definitions::class)), $params, 'pk_');
        $tbl = Ident::qi($this->db, Definitions::table());
        return $this->db->execute("DELETE FROM {$tbl} WHERE {$where}", $params);
    }

    public function restoreById(int|string|array $id): int
    {
        $soft = Definitions::softDeleteColumn();
        if (!$soft) return 0;
        return $this->updateById($id, [ $soft => null ]);
    }

    // --- READ ---------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function findById(int|string|array $id): ?array
    {
        $params=[]; $where = $this->buildPkWhere('t', $this->normalizePkInput($id, $this->pkColumns(Definitions::class)), $params, 'pk_');
        $view = Ident::qi($this->db, Definitions::contractView());
        $where = '(' . $where . ') AND ' . $this->softGuard('t');
        $row = $this->db->fetchRow("SELECT * FROM {$view} t WHERE {$where} LIMIT 1", $params);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function findAllByIds(array $ids): array
    {
        if (!$ids) return [];
        $pks = $this->pkColumns(Definitions::class);
        if (count($pks) !== 1) { throw new \InvalidArgumentException('findAllByIds only supports single-column PK.'); }

        $ids = array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? $v : null, $ids), static fn($v) => $v !== null));
        if (!$ids) return [];

        $view = Ident::qi($this->db, Definitions::contractView());
        $pkCol = Ident::q($this->db, $pks[0]);
        $where = 't.' . $pkCol . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $where = '(' . $where . ') AND ' . $this->softGuard('t');

        $rows = $this->db->fetchAll("SELECT * FROM {$view} t WHERE {$where}", $ids);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|Dto|null */
    public function getByUnique(array $keyValues, bool $asDto = false): array|Dto|null
    {
        $keyValues = $this->normalizeInputRow($keyValues);
        if (!$keyValues) return null;

        $parts = [];
        $params = [];
        foreach ($keyValues as $k => $v) {
            $k = (string)$k;
            $parts[] = 't.' . Ident::q($this->db, $k) . " = :uniq_{$k}";
            $params["uniq_{$k}"] = $v;
        }
        if (!$parts) return null;

        $view = Ident::qi($this->db, Definitions::contractView());
        $where = '(' . implode(' AND ', $parts) . ') AND ' . $this->softGuard('t');
        $row = $this->db->fetchRow("SELECT * FROM {$view} t WHERE {$where} LIMIT 1", $params);

        if (!is_array($row)) return null;
        return $asDto ? $this->mapReturnDto($row) : $row;
    }

    public function exists(string $whereSql = '1=1', array $params = []): bool
    {
        $whereSql = trim($whereSql) ?: '1=1';
        $view = Ident::qi($this->db, Definitions::contractView());
        $where = '(' . $whereSql . ') AND ' . $this->softGuard('t');
        return (bool)$this->db->fetchOne("SELECT 1 FROM {$view} t WHERE {$where} LIMIT 1", $params);
    }

    public function count(string $whereSql = '1=1', array $params = []): int
    {
        $whereSql = trim($whereSql) ?: '1=1';
        $view = Ident::qi($this->db, Definitions::contractView());
        $where = '(' . $whereSql . ') AND ' . $this->softGuard('t');
        $n = $this->db->fetchOne("SELECT COUNT(*) FROM {$view} t WHERE {$where}", $params);
        return is_numeric($n) ? (int)$n : 0;
    }

    // --- PAGINATION / LOCKING ----------------------------------------------

    public function paginate(object $criteria): array
    {
        if (!$criteria instanceof Criteria) {
            throw new \InvalidArgumentException('Expected ' . Criteria::class);
        }
        $c = $criteria;

        $view = Ident::qi($this->db, Definitions::contractView());
        $joins = $this->compileJoins($c->joins(), $c->joinParams());
        $whereSql = $c->whereSql('t');
        $params   = $c->params();
        $whereSql = ($whereSql ? "({$whereSql}) AND " : "") . $this->softGuard('t');

        $order = $c->orderBy() ?: (Definitions::defaultOrder() ?: 'id DESC');
        $order = $this->normalizeOrderBy($order, $c, $view);

        $page  = max(1, $c->page());
        $pp    = max(1, $c->perPage());
        $off   = ($page - 1) * $pp;

        $sql = "SELECT * FROM {$view} t {$joins} WHERE {$whereSql} ORDER BY {$order} LIMIT {$pp} OFFSET {$off}";
        $items = $this->db->fetchAll($sql, $params) ?: [];

        $cntSql = "SELECT COUNT(*) FROM {$view} t {$joins} WHERE {$whereSql}";
        $total = (int) ($this->db->fetchOne($cntSql, $params) ?: 0);

        return [
            'items' => $items,
            'page'  => $page,
            'per_page' => $pp,
            'total' => $total,
            'pages' => (int) ceil($total / max(1, $pp)),
        ];
    }

    /**
     * @param array{col?:string,dir?:string,pk?:string,nullsLast?:bool} $order
     * @param array{colValue:mixed,pkValue:mixed}|null $cursor
     * @return array{0:array<int,array<string,mixed>>,1:array{colValue:mixed,pkValue:mixed}|null}
     */
    public function paginateBySeek(object $criteria, array $order, ?array $cursor, int $limit): array
    {
        if (!$criteria instanceof Criteria) {
            throw new \InvalidArgumentException('Expected ' . Criteria::class);
        }
        $c = $criteria;
        $limit = max(1, min(1000, $limit));

        $view = Ident::qi($this->db, Definitions::contractView());
        $joins = $this->compileJoins($c->joins(), $c->joinParams());

        $baseWhere = $c->whereSql('t');
        $params    = $c->params();
        $baseWhere = ($baseWhere ? "({$baseWhere}) AND " : "") . $this->softGuard('t');

        $orderSpec = $this->normalizeSeekOrder($order, $c, $view);

        return KeysetPaginator::paginate(
            $this->db,
            $view,
            $joins,
            $baseWhere,
            $params,
            $orderSpec,
            $cursor,
            $limit
        );
    }

    public function lockById(int|string|array $id, string $mode = 'wait', string $strength = 'update'): ?array
    {
        $mode = in_array($mode, ['wait','nowait','skip_locked'], true) ? $mode : 'wait';
        $strength = in_array($strength, ['update','share'], true) ? $strength : 'update';

        $params=[]; $where = $this->buildPkWhere('t', $this->normalizePkInput($id, $this->pkColumns(Definitions::class)), $params, 'pk_');
        $view = Ident::qi($this->db, Definitions::contractView());
        $where = '(' . $where . ') AND ' . $this->softGuard('t');

        $lock = LockMode::sql($this->db, $mode, $strength);
        $row = $this->db->fetchRow("SELECT * FROM {$view} t WHERE {$where} {$lock}", $params);
        return is_array($row) ? $row : null;
    }

    public function existsById(int|string|array $id): bool {
        $params=[]; $where = $this->buildPkWhere('t', $this->normalizePkInput($id, $this->pkColumns(Definitions::class)), $params, 'pk_');
        $view = Ident::qi($this->db, Definitions::contractView());
        $where = '(' . $where . ') AND ' . $this->softGuard('t');
        return (bool)$this->db->fetchOne("SELECT 1 FROM {$view} t WHERE {$where} LIMIT 1", $params);
    }

    // === Generated unique helpers (per table UNIQUE/PK) ===
    
    /** @return array<string,mixed>|\BlackCat\Database\Packages\WebauthnChallenges\Dto\WebauthnChallengeDto|null */
    public function getByRpIdAndChallengeHash(string $rpId, string $challengeHash, bool $asDto = false): array|\BlackCat\Database\Packages\WebauthnChallenges\Dto\WebauthnChallengeDto|null {
        return $this->getByUnique([ 'rp_id' => $rpId, 'challenge_hash' => $challengeHash ], $asDto);
    }
    public function existsByRpIdAndChallengeHash(string $rpId, string $challengeHash): bool {
        $where = 't.' . Ident::q($this->db, 'rp_id') . ' = :uniq_rp_id' . ' AND ' . 't.' . Ident::q($this->db, 'challenge_hash') . ' = :uniq_challenge_hash';
        return $this->exists($where, [ 'uniq_rp_id' => $rpId, 'uniq_challenge_hash' => $challengeHash ]);
    }
    /** @return int|string|null */
    public function getIdByRpIdAndChallengeHash(string $rpId, string $challengeHash) {
        $row = $this->getByRpIdAndChallengeHash($rpId, $challengeHash, false);
        if (!is_array($row)) { return null; }
        return $row['id'] ?? null;
    }

}
