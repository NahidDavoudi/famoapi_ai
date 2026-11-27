#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$rootDir = dirname(__DIR__);
$autoload = $rootDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run `composer install` first.\n");
    exit(1);
}

require_once $autoload;

$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

foreach ((array) getenv() as $envKey => $envVal) {
    if (!isset($_ENV[$envKey]) && is_string($envVal)) {
        $_ENV[$envKey] = $envVal;
    }
}

date_default_timezone_set('UTC');

use App\Modules\Curriculum\CurriculumRepository;

$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
$asJson = in_array('--json', $args, true);

if ($showHelp) {
    $usage = <<<'TEXT'
FAMO Curriculum Nodes Enrichment Runner (scripts/enrich_curriculum_nodes.php)

Computes and persists `node_type`, `segment_id`, `lesson_id`, `grade_label`,
`group_label`, `full_path`, and `normalized_label` for every row in `curriculum_nodes`.
Safe and idempotent — re-run whenever `curriculum_nodes` is refreshed from Lernito.

Usage:
  php scripts/enrich_curriculum_nodes.php          Enrich all nodes and print summary
  php scripts/enrich_curriculum_nodes.php --json   Print JSON summary
  php scripts/enrich_curriculum_nodes.php --help   Show this help message

TEXT;
    fwrite(STDOUT, $usage);
    exit(0);
}

try {
    $startedAt = microtime(true);
    $repo = new CurriculumRepository();
    $result = $repo->enrichAllNodes();
    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
    $result['elapsed_ms'] = $elapsedMs;

    if ($asJson) {
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(0);
    }

    fwrite(STDOUT, "Enriched {$result['updated']} / {$result['total_nodes']} curriculum_nodes rows in {$elapsedMs} ms.\n");
    if (!empty($result['by_type'])) {
        fwrite(STDOUT, "Breakdown by node_type:\n");
        foreach ($result['by_type'] as $type => $count) {
            fwrite(STDOUT, sprintf("  - %-10s : %d\n", (string) $type, (int) $count));
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Enrichment error: {$e->getMessage()}\n");
    exit(1);
}
