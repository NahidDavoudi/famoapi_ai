<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

/**
 * Contract for communicating with the internal Node Lernito gateway.
 */
interface LernitoClientInterface
{
    /**
     * Fetch the complete list of shelves (and their records) for a given account.
     *
     * @return array{
     *   shelves: array<int, array<string, mixed>>,
     *   count: int,
     *   cachedAt: ?string,
     *   fromCache: bool
     * }
     */
    public function fetchShelves(string $account, bool $force = true, ?int $timeoutSeconds = null): array;

    /**
     * Fetch raw question documents by Lernito IDs (max 100 per call).
     *
     * @param array<int, string> $ids
     * @return array{
     *   questions: array<int, array<string, mixed>>,
     *   requestedCount: int,
     *   fetchedCount: int,
     *   missingIds: array<int, string>
     * }
     */
    public function fetchQuestions(array $ids, string $account, ?int $timeoutSeconds = null): array;
}
