<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\WebauthnChallenges\Mapper;

use BlackCat\Database\Packages\WebauthnChallenges\Dto\WebauthnChallengeDto;
use BlackCat\Database\Packages\WebauthnChallenges\Definitions;
use DateTimeZone;
use BlackCat\Database\Support\DtoHydrator;

/**
 * Bidirectional mapper between DB rows and DTO WebauthnChallengeDto.
 */
final class WebauthnChallengeDtoMapper
{
    /** @var array<string,string> Column -> DTO property */
    private const COL_TO_PROP = [
        'id' => 'id',
        'rp_id' => 'rpId',
        'challenge_hash' => 'challengeHash',
        'metadata' => 'metadata',
        'expires_at' => 'expiresAt',
        'created_at' => 'createdAt',
    ];

    /** @var string[] */
    private const BOOL_COLS   = [];
    /** @var string[] */
    private const INT_COLS    = [ 'id' ];
    /** @var string[] */
    private const FLOAT_COLS  = [];
    /** @var string[] */
    private const JSON_COLS   = [ 'metadata' ];
    /** @var string[] */
    private const DATE_COLS   = [ 'expires_at', 'created_at' ];
    /** @var string[] */
    private const BIN_COLS    = [];

    /** Preferred timezone for parsing/serializing dates */
    private const TZ = 'UTC';

    private static ?DateTimeZone $tzObj = null;

    private static function tz(): DateTimeZone
    {
        if (!(self::$tzObj instanceof DateTimeZone)) {
            self::$tzObj = new DateTimeZone(self::TZ);
        }
        return self::$tzObj;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): WebauthnChallengeDto
    {
        /** @var WebauthnChallengeDto */
        return DtoHydrator::fromRow(
            WebauthnChallengeDto::class,
            $row,
            self::COL_TO_PROP,
            self::BOOL_COLS,
            self::INT_COLS,
            self::FLOAT_COLS,
            self::JSON_COLS,
            self::DATE_COLS,
            self::BIN_COLS,
            self::tz()
        );
    }

    public static function fromRowOrNull(?array $row): ?WebauthnChallengeDto
    {
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * @param WebauthnChallengeDto $dto
     * @param string[]|null $onlyProps
     * @return array<string,mixed>
     */
    public static function toRow(WebauthnChallengeDto $dto, ?array $onlyProps = null): array
    {
        return DtoHydrator::toRow(
            $dto,
            self::COL_TO_PROP,
            self::BOOL_COLS,
            self::INT_COLS,
            self::FLOAT_COLS,
            self::JSON_COLS,
            self::DATE_COLS,
            self::BIN_COLS,
            self::tz(),
            $onlyProps
        );
    }

    public static function toRowNonNull(WebauthnChallengeDto $dto, ?array $onlyProps = null): array
    {
        $row = self::toRow($dto, $onlyProps);
        foreach ($row as $k => $v) {
            if ($v === null) unset($row[$k]);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $original
     * @param string[] $ignore
     * @return array<string,mixed>
     */
    public static function diff(WebauthnChallengeDto $dto, array $original, array $ignore = [], bool $coerce = true): array
    {
        $now = self::toRow($dto);

        if ($ignore) {
            $drop = array_fill_keys($ignore, true);
            $now = array_filter($now, static fn($k) => !isset($drop[$k]), ARRAY_FILTER_USE_KEY);
        }

        $out = [];
        foreach ($now as $k => $v) {
            $orig = $original[$k] ?? null;

            if ($coerce && is_scalar($v) && is_scalar($orig)) {
                $vn = is_bool($v) ? (int)$v : (string)$v;
                $on = is_bool($orig) ? (int)$orig : (string)$orig;
                if ($vn === $on) continue;
            } elseif ($v == $orig) {
                continue;
            }

            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,WebauthnChallengeDto>
     */
    public static function hydrateList(array $rows): array
    {
        /** @var array<int,WebauthnChallengeDto> */
        return DtoHydrator::hydrateList(
            WebauthnChallengeDto::class,
            $rows,
            self::COL_TO_PROP,
            self::BOOL_COLS,
            self::INT_COLS,
            self::FLOAT_COLS,
            self::JSON_COLS,
            self::DATE_COLS,
            self::BIN_COLS,
            self::tz()
        );
    }

    /** @return array<string,mixed> */
    public static function toSafeArray(WebauthnChallengeDto $dto): array
    {
        $row = self::toRow($dto);

        $pii = [];
        if (\method_exists(Definitions::class, 'piiColumns')) {
            $decl = (array) Definitions::piiColumns();

            if ($decl) {
                $map = self::COL_TO_PROP;
                $rev = array_flip($map);

                foreach ($decl as $name) {
                    $name = (string) $name;
                    $col = array_key_exists($name, $row) ? $name : ($rev[$name] ?? $name);
                    $pii[$col] = true;
                }
            }
        }

        if ($pii) {
            foreach ($row as $k => &$v) {
                if (isset($pii[$k]) && $v !== null && $v !== '') {
                    $v = '***';
                }
            }
            unset($v);
        }

        return $row;
    }

    /**
     * @param iterable<int,array<string,mixed>> $rows
     * @return \Generator<int,WebauthnChallengeDto>
     */
    public static function hydrate(iterable $rows): \Generator
    {
        foreach ($rows as $row) {
            yield self::fromRow($row);
        }
    }
}
