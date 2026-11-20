<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ServerRequestInterface;

final class Sorting
{
    public const VALID_ORDERS = ['ASC', 'DESC'];

    /**
     * Resolve a safe ORDER BY fragment from a PSR-7 request or query-parameter array
     * using an explicit per-endpoint whitelist.
     *
     * User input is never interpolated into SQL; only static whitelist expressions
     * and hardcoded ASC/DESC keywords appear in the returned SQL fragment.
     *
     * @param ServerRequestInterface|array<string, mixed> $source
     * @param array<string, string|array{
     *     expr: string|list<string>,
     *     collate?: ?string,
     *     nulls_last?: bool,
     *     secondary?: list<array{expr: string, dir?: string, collate?: ?string, nulls_last?: bool}>,
     *     tiebreaker?: ?string
     * }> $whitelist
     * @return array{
     *     key: string,
     *     order: 'asc'|'desc',
     *     sql_order: 'ASC'|'DESC',
     *     order_by: string,
     *     is_explicit: bool
     * }
     */
    public static function resolve(
        ServerRequestInterface|array $source,
        array $whitelist,
        string $defaultKey,
        string $defaultOrder = 'DESC',
        string $tiebreaker = 'id',
        ?string $driver = null
    ): array {
        if ($whitelist === [] || !array_key_exists($defaultKey, $whitelist)) {
            throw new \InvalidArgumentException('Sorting defaultKey must exist in whitelist.');
        }

        $normalizedDefaultOrder = strtoupper(trim($defaultOrder)) === 'ASC' ? 'ASC' : 'DESC';
        $params = $source instanceof ServerRequestInterface ? $source->getQueryParams() : $source;

        $hasRawSort = array_key_exists('sort', $params)
            && (!is_string($params['sort']) || trim($params['sort']) !== '');
        $hasRawOrder = array_key_exists('order', $params)
            && (!is_string($params['order']) || trim($params['order']) !== '');
        $isExplicit = $hasRawSort || $hasRawOrder;

        $selectedKey = $defaultKey;
        $selectedOrder = $normalizedDefaultOrder;

        if ($hasRawSort) {
            $candidateKey = is_string($params['sort']) ? trim($params['sort']) : '';
            if ($candidateKey !== '' && array_key_exists($candidateKey, $whitelist)) {
                $selectedKey = $candidateKey;
                $selectedOrder = self::parseOrder($params['order'] ?? null, $normalizedDefaultOrder);
            } else {
                // Unknown/malicious sort key: fall back completely to the default sort + direction.
                $selectedKey = $defaultKey;
                $selectedOrder = $normalizedDefaultOrder;
            }
        } elseif ($hasRawOrder) {
            $selectedOrder = self::parseOrder($params['order'] ?? null, $normalizedDefaultOrder);
        }

        $entry = self::normalizeEntry($whitelist[$selectedKey]);
        $resolvedDriver = self::detectDriver($driver);
        $resolvedTiebreaker = $entry['tiebreaker'] ?? $tiebreaker;
        if ($resolvedTiebreaker === '') {
            $resolvedTiebreaker = 'id';
        }

        $terms = [];

        foreach ($entry['exprs'] as $expr) {
            $terms[] = self::formatTerm($expr, $selectedOrder, $entry['collate'], $entry['nulls_last'], $resolvedDriver);
        }

        foreach ($entry['secondary'] as $sec) {
            $secExpr = $sec['expr'];
            $secDir = strtoupper(trim((string) ($sec['dir'] ?? $selectedOrder))) === 'ASC' ? 'ASC' : 'DESC';
            $secCollate = $sec['collate'] ?? null;
            $secNullsLast = $sec['nulls_last'] ?? true;
            $terms[] = self::formatTerm($secExpr, $secDir, $secCollate, $secNullsLast, $resolvedDriver);
        }

        if ($entry['exprs'] !== [$resolvedTiebreaker] || $entry['secondary'] !== []) {
            $terms[] = "{$resolvedTiebreaker} {$selectedOrder}";
        }

        return [
            'key'         => $selectedKey,
            'order'       => $selectedOrder === 'ASC' ? 'asc' : 'desc',
            'sql_order'   => $selectedOrder,
            'order_by'    => 'ORDER BY ' . implode(', ', $terms),
            'is_explicit' => $isExplicit,
        ];
    }

    /**
     * Convenience wrapper matching resolve() that returns only the safe ORDER BY clause.
     *
     * @param ServerRequestInterface|array<string, mixed> $source
     * @param array<string, mixed> $whitelist
     */
    public static function orderBy(
        ServerRequestInterface|array $source,
        array $whitelist,
        string $defaultKey,
        string $defaultOrder = 'DESC',
        string $tiebreaker = 'id',
        ?string $driver = null
    ): string {
        return self::resolve($source, $whitelist, $defaultKey, $defaultOrder, $tiebreaker, $driver)['order_by'];
    }

    /**
     * Append sort metadata to a pagination payload only when explicit sort/order query params were supplied,
     * preserving existing response shapes when no sort parameters are sent.
     *
     * @param array{page: int, per_page: int, total: int, total_pages: int} $pagination
     * @param array{key: string, order: 'asc'|'desc', is_explicit: bool} $resolvedSort
     * @return array<string, mixed>
     */
    public static function attachToPagination(array $pagination, array $resolvedSort): array
    {
        if (!empty($resolvedSort['is_explicit'])) {
            $pagination['sort'] = $resolvedSort['key'];
            $pagination['order'] = $resolvedSort['order'];
        }

        return $pagination;
    }

    /**
     * @return 'ASC'|'DESC'
     */
    private static function parseOrder(mixed $rawOrder, string $fallback): string
    {
        $normalizedFallback = strtoupper(trim($fallback)) === 'ASC' ? 'ASC' : 'DESC';
        if (!is_string($rawOrder)) {
            return $normalizedFallback;
        }

        $candidate = strtoupper(trim($rawOrder));
        if ($candidate === 'ASC' || $candidate === 'DESC') {
            return $candidate;
        }

        return $normalizedFallback;
    }

    /**
     * @param string|array<string, mixed> $rawEntry
     * @return array{
     *     exprs: list<string>,
     *     collate: ?string,
     *     nulls_last: bool,
     *     secondary: list<array{expr: string, dir?: string, collate?: ?string, nulls_last?: bool}>,
     *     tiebreaker: ?string
     * }
     */
    private static function normalizeEntry(string|array $rawEntry): array
    {
        if (is_string($rawEntry)) {
            return [
                'exprs'      => [$rawEntry],
                'collate'    => null,
                'nulls_last' => true,
                'secondary'  => [],
                'tiebreaker' => null,
            ];
        }

        $exprRaw = $rawEntry['expr'] ?? [];
        $exprs = is_array($exprRaw) ? array_values($exprRaw) : [$exprRaw];
        if ($exprs === []) {
            throw new \InvalidArgumentException('Sorting whitelist entry must define a non-empty expr.');
        }

        $collate = isset($rawEntry['collate']) && is_string($rawEntry['collate']) && preg_match('/^[A-Za-z0-9_]+$/', $rawEntry['collate']) === 1
            ? $rawEntry['collate']
            : null;

        $tiebreaker = isset($rawEntry['tiebreaker']) && is_string($rawEntry['tiebreaker']) && trim($rawEntry['tiebreaker']) !== ''
            ? trim($rawEntry['tiebreaker'])
            : null;

        return [
            'exprs'      => $exprs,
            'collate'    => $collate,
            'nulls_last' => (bool) ($rawEntry['nulls_last'] ?? true),
            'secondary'  => is_array($rawEntry['secondary'] ?? null) ? array_values($rawEntry['secondary']) : [],
            'tiebreaker' => $tiebreaker,
        ];
    }

    private static function formatTerm(
        string $expr,
        string $dir,
        ?string $collate,
        bool $nullsLast,
        string $driver
    ): string {
        $collateSql = '';
        if ($driver === 'mysql' && $collate !== null && preg_match('/^[A-Za-z0-9_]+$/', $collate) === 1) {
            $collateSql = " COLLATE {$collate}";
        }

        $directionTerm = "{$expr}{$collateSql} {$dir}";

        if ($nullsLast) {
            return "CASE WHEN {$expr} IS NULL THEN 1 ELSE 0 END ASC, {$directionTerm}";
        }

        return $directionTerm;
    }

    private static function detectDriver(?string $driver): string
    {
        if ($driver !== null && trim($driver) !== '') {
            return strtolower(trim($driver));
        }

        if (Database::hasConnection()) {
            try {
                return strtolower((string) Database::getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME));
            } catch (\Throwable) {
                // Fall back to environment driver name below.
            }
        }

        return strtolower(trim((string) ($_ENV['DB_DRIVER'] ?? 'mysql')));
    }
}
