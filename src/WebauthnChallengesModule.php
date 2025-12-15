<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\WebauthnChallenges;

use BlackCat\Database\SqlDialect;
use BlackCat\Database\Contracts\ModuleInterface;
use BlackCat\Database\Support\SqlIdentifier;
use BlackCat\Database\Support\SqlDirectoryRunner;
use BlackCat\Database\Support\SchemaIntrospector;
use BlackCat\Core\Database as Database;

final class WebauthnChallengesModule implements ModuleInterface
{
    public function name(): string { return 'table-webauthn_challenges'; }
    public function table(): string { return 'webauthn_challenges'; }
    public function version(): string { return '1.0.0'; }

    /** @return string[] */
    public function dialects(): array { return [ 'mysql', 'postgres' ]; }
    /** @return string[] */
    public function dependencies(): array { return []; }

    public static function contractView(): string { return 'vw_webauthn_challenges'; }

    public function install(Database $db, SqlDialect $d): void
    {
        SqlDirectoryRunner::run($db, $d, __DIR__ . '/../schema');

        $table = SqlIdentifier::qi($db, $this->table());
        $view  = SqlIdentifier::qi($db, self::contractView());

        if ($d->isMysql()) {
            $createViewSql = <<<'SQL'
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
SQL;
        } else {
            $createViewSql = <<<'SQL'
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
SQL;
        }

        if (\class_exists('\\BlackCat\\Database\\Support\\DdlGuard')) {
            (new \BlackCat\Database\Support\DdlGuard($db, $d))->applyCreateView($createViewSql);
        } else {
            $db->exec($createViewSql);
        }
    }

    public function upgrade(Database $db, SqlDialect $d, string $from): void
    {
    }

    public function uninstall(Database $db, SqlDialect $d): void
    {
        $qiV = SqlIdentifier::qi($db, self::contractView());
        try {
            $db->exec("DROP VIEW IF EXISTS {$qiV}" . ($d->isMysql() ? "" : " CASCADE"));
        } catch (\Throwable) {
        }
    }

    public function status(Database $db, SqlDialect $d): array
    {
        $table = $this->table();
        $view  = self::contractView();

        $hasTable = SchemaIntrospector::hasTable($db, $d, $table);
        $hasView  = SchemaIntrospector::hasView($db, $d, $view);

        $expectedIdx = [ 'idx_webauthn_challenge_expires' ];
        if ($d->isMysql()) {
            $expectedIdx = array_values(array_filter(
                $expectedIdx,
                static fn(string $n): bool => !str_starts_with($n, 'gin_') && !str_starts_with($n, 'gist_')
            ));
        }
        $expectedFk  = [];

        $haveIdx = $hasTable ? SchemaIntrospector::listIndexes($db, $d, $table)     : [];
        $haveFk  = $hasTable ? SchemaIntrospector::listForeignKeys($db, $d, $table) : [];

        $missingIdx = array_values(array_diff($expectedIdx, $haveIdx));
        $missingFk  = array_values(array_diff($expectedFk, $haveFk));

        return [
            'table'       => $hasTable,
            'view'        => $hasView,
            'missing_idx' => $missingIdx,
            'missing_fk'  => $missingFk,
            'version'     => $this->version(),
        ];
    }

    public function info(): array
    {
        return [
            'table'       => $this->table(),
            'view'        => self::contractView(),
            'columns'     => Definitions::columns(),
            'version'     => $this->version(),
            'dialects'    => [ 'mysql', 'postgres' ],
            'indexes'     => [ 'idx_webauthn_challenge_expires' ],
            'foreignKeys' => [],
        ];
    }
}
