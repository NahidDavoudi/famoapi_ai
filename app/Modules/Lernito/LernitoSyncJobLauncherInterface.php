<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

/**
 * Contract for spawning the asynchronous background Lernito sync CLI process.
 */
interface LernitoSyncJobLauncherInterface
{
    /**
     * Launch `scripts/lernito_sync.php` asynchronously in the background.
     *
     * @param array<int, string> $accounts Validated account identifiers
     * @param array{
     *   job_id: string,
     *   lock_token: string,
     *   mode: string,
     *   force_links: bool,
     *   retry_missing: bool,
     *   max_questions: ?int,
     *   triggered_by: string
     * } $options
     */
    public function launch(array $accounts, array $options): void;
}
