<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use RuntimeException;

/**
 * Spawns `scripts/lernito_sync.php` as a detached background CLI process so
 * POST /api/v1/admin/lernito/sync returns 202 Accepted immediately without blocking PHP-FPM.
 */
final class LernitoSyncJobLauncher implements LernitoSyncJobLauncherInterface
{
    private LernitoConfig $config;
    private string $scriptPath;
    private string $logPath;

    public function __construct(?LernitoConfig $config = null, ?string $scriptPath = null, ?string $logPath = null)
    {
        $this->config = $config ?? LernitoConfig::fromEnv();
        $root = dirname(__DIR__, 3);
        $this->scriptPath = $scriptPath ?? ($root . '/scripts/lernito_sync.php');
        $this->logPath = $logPath ?? ($root . '/storage/logs/lernito_sync.log');
    }

    public function launch(array $accounts, array $options): void
    {
        if (!is_file($this->scriptPath)) {
            throw new RuntimeException('اسکریپت همگام‌سازی لرنیتو (scripts/lernito_sync.php) یافت نشد.');
        }

        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $args = [
            escapeshellarg($this->config->phpBinary),
            escapeshellarg($this->scriptPath),
            '--job-id=' . escapeshellarg($options['job_id']),
            '--lock-token=' . escapeshellarg($options['lock_token']),
            '--triggered-by=' . escapeshellarg($options['triggered_by']),
        ];

        if ($accounts !== []) {
            $safeAccounts = [];
            foreach ($accounts as $acc) {
                $safeAccounts[] = $this->config->assertValidAccount($acc);
            }
            $args[] = '--account=' . escapeshellarg(implode(',', $safeAccounts));
        }

        if (($options['mode'] ?? 'sync') === 'refresh_versions') {
            $args[] = '--refresh-versions';
        }
        if (!empty($options['force_links'])) {
            $args[] = '--force-links';
        }
        if (!empty($options['retry_missing'])) {
            $args[] = '--retry-missing';
        }
        if (isset($options['max_questions']) && is_int($options['max_questions']) && $options['max_questions'] > 0) {
            $args[] = '--max-questions=' . (int) $options['max_questions'];
        }

        $cmd = implode(' ', $args);
        $logTarget = escapeshellarg($this->logPath);

        if (DIRECTORY_SEPARATOR === '\\') {
            $fullCmd = 'start /B "" ' . $cmd . ' >> ' . $logTarget . ' 2>&1';
            $handle = @popen($fullCmd, 'r');
            if ($handle === false) {
                throw new RuntimeException('اجرای پس‌زمینه همگام‌سازی لرنیتو ناموفق بود.');
            }
            pclose($handle);
            return;
        }

        $fullCmd = $cmd . ' >> ' . $logTarget . ' 2>&1 &';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = @proc_open($fullCmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('اجرای پس‌زمینه همگام‌سازی لرنیتو ناموفق بود.');
        }
        proc_close($proc);
    }
}
