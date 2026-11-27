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

use App\Core\Database;
use App\Modules\Curriculum\CurriculumRepository;
use App\Modules\Curriculum\PersianTextNormalizer;
use App\Modules\Exams\ExamResult;

$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
$apply = in_array('--apply', $args, true);
$asJson = in_array('--json', $args, true);
$overwriteExisting = in_array('--force', $args, true);
$matchChapters = in_array('--match-chapters', $args, true);

if ($showHelp) {
    $usage = <<<'TEXT'
FAMO Legacy Exam Results -> Curriculum Nodes Backfill (scripts/backfill_exam_results_curriculum.php)

Standardizes existing `exam_results` rows onto `curriculum_nodes` IDs (`subject_id`,
`topic_ids_json`, and `exam_result_topics`) and trims dirty subject/chapter strings.
By default runs in DRY-RUN mode without modifying the database.

Usage:
  php scripts/backfill_exam_results_curriculum.php                   Dry-run preview (default)
  php scripts/backfill_exam_results_curriculum.php --apply           Apply changes in a transaction
  php scripts/backfill_exam_results_curriculum.php --match-chapters  Also map unambiguous chapter numbers to topic_ids
  php scripts/backfill_exam_results_curriculum.php --force           Re-process rows that already have subject_id
  php scripts/backfill_exam_results_curriculum.php --json            Output machine-readable JSON report
  php scripts/backfill_exam_results_curriculum.php --help            Show this help message

Notes:
  - Run `php scripts/enrich_curriculum_nodes.php` first so `curriculum_nodes` has `node_type` populated.
  - Rows with NULL/empty chapter always receive `topic_ids_json = []` (empty array).
  - Unmatched subjects are left with `subject_id = NULL` and listed in the report for manual review.

TEXT;
    fwrite(STDOUT, $usage);
    exit(0);
}

/**
 * Clean legacy dirty subject string and extract optional embedded grade hint + canonical lesson candidates.
 *
 * @return array{cleaned: string, grade_hint: ?string, group_hint: ?string, candidates: array<int, string>}
 */
function parseLegacySubject(string $rawSubject, ?string $rawChapter, ?string $studentField): array
{
    $norm = PersianTextNormalizer::normalize($rawSubject);
    // Strip accidental keyboard symbols observed in legacy data (e.g. "زیست÷", "ریاضی÷", "فیزیک ض")
    $norm = preg_replace('/[÷×\*]+/u', '', $norm) ?? $norm;
    $norm = preg_replace('/\s+ض$/u', '', $norm) ?? $norm;
    $norm = trim($norm);

    $gradeHint = null;
    $groupHint = null;

    // Detect explicit grade keywords in subject first, then chapter
    $gradePatterns = [
        '/(?:^|\s|[-_])(دوازدهم|12)(?:\s|$|[-_])/u' => 'دوازدهم',
        '/(?:^|\s|[-_])(یازدهم|11)(?:\s|$|[-_])/u'  => 'یازدهم',
        '/(?:^|\s|[-_])(دهم|10)(?:\s|$|[-_])/u'     => 'دهم',
        '/(?:^|\s|[-_])(نهم|9)(?:\s|$|[-_])/u'      => 'نهم',
        '/(?:^|\s|[-_])(هشتم|8)(?:\s|$|[-_])/u'     => 'هشتم',
        '/(?:^|\s|[-_])(هفتم|7)(?:\s|$|[-_])/u'     => 'هفتم',
    ];

    foreach ($gradePatterns as $pattern => $label) {
        if (preg_match($pattern, $norm) === 1) {
            $gradeHint = $label;
            $norm = trim((string) preg_replace($pattern, ' ', $norm));
            break;
        }
    }

    // Book number suffixes in subject (e.g. "شیمی 3" -> دوازدهم, "ریاضی-2" -> یازدهم, "فنون-2" -> یازدهم, "هندسه 3" -> دوازدهم)
    if ($gradeHint === null && preg_match('/[-_\s]+([123])$/u', $norm, $m) === 1) {
        $bookNum = (int) $m[1];
        $baseBeforeNum = trim((string) preg_replace('/[-_\s]+[123]$/u', '', $norm));
        $baseLoose = PersianTextNormalizer::looseKey($baseBeforeNum);
        if (str_contains($baseLoose, 'حسابان')) {
            // حسابان 1 = یازدهم, حسابان 2 = دوازدهم
            $gradeHint = $bookNum === 1 ? 'یازدهم' : ($bookNum === 2 ? 'دوازدهم' : null);
        } elseif (str_contains($baseLoose, 'فلسفه')) {
            // فلسفه 1 = یازدهم, فلسفه 2 = دوازدهم
            $gradeHint = $bookNum === 1 ? 'یازدهم' : ($bookNum === 2 ? 'دوازدهم' : null);
        } else {
            $gradeHint = match ($bookNum) {
                1 => 'دهم',
                2 => 'یازدهم',
                3 => 'دوازدهم',
                default => null,
            };
        }
        $norm = $baseBeforeNum;
    }

    // Fallback grade hint from chapter text (e.g. "فصل 1 دهم", "یازدهم فصل 2", "دوازدهم فصل 6")
    if ($gradeHint === null && $rawChapter !== null && trim($rawChapter) !== '') {
        $normChap = PersianTextNormalizer::normalize($rawChapter);
        foreach ($gradePatterns as $pattern => $label) {
            if (preg_match($pattern, $normChap) === 1) {
                $gradeHint = $label;
                break;
            }
        }
    }

    $norm = trim((string) preg_replace('/\s+/u', ' ', $norm));
    $loose = PersianTextNormalizer::looseKey($norm);
    $normField = PersianTextNormalizer::fieldToGroupLabel($studentField);

    // Canonical alias table built from real FAMO exam_results data
    $aliasMap = [
        'زیست'             => ['زیست شناسی'],
        'زیستشناسی'        => ['زیست شناسی'],
        'علومزیست'         => ['علوم'],
        'علومشیمی'         => ['علوم'],
        'علومفیزیک'        => ['علوم'],
        'علوم'             => ['علوم'],
        'شیمی'             => ['شیمی'],
        'فیزیک'            => ['فیزیک'],
        'حسابان'           => ['حسابان'],
        'هندسه'            => ['هندسه'],
        'گسسته'            => ['ریاضیات گسسته', 'گسسته'],
        'امارواحتمال'      => ['آمار و احتمال'],
        'آمارواحتمال'      => ['آمار و احتمال'],
        'ریاضی'            => $normField === 'ادبیات و علوم انسانی' ? ['ریاضی و آمار', 'ریاضی'] : ['ریاضی'],
        'فنون'             => ['علوم و فنون ادبی'],
        'فنونادبی'         => ['علوم و فنون ادبی'],
        'فنونوادبیاتفارسی' => ['علوم و فنون ادبی'],
        'ادبیات'           => ['فارسی', 'ادبیات'],
        'فارسی'            => ['فارسی'],
        'عربی'             => ['عربی', 'عربی، زبان قرآن'],
        'جامعه'            => ['جامعه شناسی', 'علوم اجتماعی'],
        'جامعهشناسی'       => ['جامعه شناسی'],
        'علوماجتماعی'      => ['جامعه شناسی', 'علوم اجتماعی'],
        'جغرافیا'          => ['جغرافیا', 'جغرافیای ایران'],
        'تاریخ'            => ['تاریخ', 'تاریخ معاصر ایران'],
        'روانشناسی'        => ['روانشناسی'],
        'فلسفه'            => ['فلسفه'],
        'فلسفهومنطق'       => ['فلسفه', 'منطق'],
        'منطق'             => ['منطق'],
        'مطالعات'          => ['مطالعات اجتماعی'],
        'مطالعاتاجتماعی'   => ['مطالعات اجتماعی'],
        'دینی'             => ['دین و زندگی', 'پیامهای آسمان'],
        'دینوزندگی'        => ['دین و زندگی'],
        'زبان'             => ['زبان انگلیسی'],
        'زبانانگلیسی'      => ['زبان انگلیسی'],
    ];

    if (in_array($loose, ['حسابان', 'هندسه', 'گسسته', 'آمارواحتمال', 'امارواحتمال'], true)) {
        $groupHint = 'ریاضی و فیزیک';
    } elseif (in_array($loose, ['زیست', 'زیستشناسی'], true)) {
        $groupHint = 'علوم تجربی';
    } elseif (in_array($loose, ['فنون', 'فنونادبی', 'فنونوادبیاتفارسی', 'جامعه', 'جامعهشناسی', 'جغرافیا', 'تاریخ', 'روانشناسی', 'فلسفه', 'فلسفهومنطق', 'منطق'], true)) {
        $groupHint = 'ادبیات و علوم انسانی';
    }

    $candidates = $aliasMap[$loose] ?? [$norm];
    if (!in_array($norm, $candidates, true) && $norm !== '') {
        $candidates[] = $norm;
    }

    return [
        'cleaned'    => $norm,
        'grade_hint' => $gradeHint,
        'group_hint' => $groupHint,
        'candidates' => $candidates,
    ];
}

/**
 * Optionally extract unambiguous chapter IDs under a matched lesson node.
 * Returns [] when chapter is null/empty or cannot be matched with high confidence.
 *
 * @param array<int, array<string, mixed>> $lessonChapters
 * @return array<int, int>
 */
function matchUnambiguousChapters(?string $rawChapter, array $lessonChapters): array
{
    if ($rawChapter === null || trim($rawChapter) === '' || $lessonChapters === []) {
        return [];
    }

    $norm = PersianTextNormalizer::normalize($rawChapter);
    if (str_contains($norm, 'جامع')) {
        return [];
    }

    // Build lookup by chapter/lesson number (e.g. "فصل 1: ..." -> 1, "درس 3: ..." -> 3)
    $byNumber = [];
    foreach ($lessonChapters as $ch) {
        $cNorm = PersianTextNormalizer::normalize((string) $ch['label']);
        if (preg_match('/^(?:فصل|درس|بخش)\s*(\d+)/u', $cNorm, $m) === 1) {
            $byNumber[(int) $m[1]] = (int) $ch['id'];
        }
    }

    // Match explicit patterns like "فصل 1", "فصل 1 و 2", "درس 3و4", "درس 1 و 2 و 3"
    $matchedIds = [];
    if (preg_match('/(?:فصل|درس)\s*([0-9]+(?:\s*(?:و|،|,)\s*[0-9]+)*)/u', $norm, $m) === 1) {
        if (preg_match_all('/\d+/u', $m[1], $nums) > 0) {
            foreach ($nums[0] as $numStr) {
                $n = (int) $numStr;
                if (isset($byNumber[$n])) {
                    $matchedIds[] = $byNumber[$n];
                }
            }
        }
    }

    // Fallback: direct substring match of chapter title (without "فصل N:")
    if ($matchedIds === []) {
        $looseInput = PersianTextNormalizer::looseKey($norm);
        foreach ($lessonChapters as $ch) {
            $cNorm = PersianTextNormalizer::normalize((string) $ch['label']);
            $titleOnly = trim((string) preg_replace('/^(?:فصل|درس|بخش)\s*\d+\s*[:：\-]?\s*/u', '', $cNorm));
            $looseTitle = PersianTextNormalizer::looseKey($titleOnly);
            if (mb_strlen($looseTitle, 'UTF-8') >= 4 && $looseInput === $looseTitle) {
                $matchedIds[] = (int) $ch['id'];
            }
        }
    }

    return ExamResult::normalizeTopicIdArray($matchedIds);
}

try {
    $pdo = Database::getConnection();
    $currRepo = new CurriculumRepository($pdo);

    // Ensure curriculum_nodes has been enriched
    $unenriched = (int) $pdo->query('SELECT COUNT(*) FROM curriculum_nodes WHERE node_type IS NULL')->fetchColumn();
    if ($unenriched > 0) {
        fwrite(STDERR, "Detected {$unenriched} un-enriched curriculum_nodes rows; running enrichment first...\n");
        $currRepo->enrichAllNodes();
    }

    // Preload chapter nodes keyed by lesson_id (used when --match-chapters is passed)
    $chaptersByLesson = [];
    if ($matchChapters) {
        $chRows = $pdo->query(
            "SELECT id, lesson_id, label FROM curriculum_nodes WHERE node_type = 'chapter' AND lesson_id IS NOT NULL ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chRows as $cr) {
            $chaptersByLesson[(int) $cr['lesson_id']][] = $cr;
        }
    }

    $whereSql = $overwriteExisting ? '1=1' : 'er.subject_id IS NULL';
    $rows = $pdo->query(
        "SELECT er.id, er.student_id, er.exam_date, er.subject, er.chapter, er.subject_id, er.topic_ids_json,
                s.grade AS student_grade, s.field AS student_field
         FROM exam_results er
         LEFT JOIN students s ON s.id = er.student_id
         WHERE {$whereSql}
         ORDER BY er.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'mode'             => $apply ? 'apply' : 'dry-run',
        'match_chapters'   => $matchChapters,
        'scanned'          => count($rows),
        'matched_subject'  => 0,
        'matched_chapters' => 0,
        'unmatched'        => 0,
        'trimmed_only'     => 0,
    ];
    $unmatchedSamples = [];
    $plannedUpdates = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $rawSubject = (string) ($row['subject'] ?? '');
        $rawChapter = $row['chapter'] !== null ? (string) $row['chapter'] : null;
        $studentGrade = $row['student_grade'] !== null ? (string) $row['student_grade'] : null;
        $studentField = $row['student_field'] !== null ? (string) $row['student_field'] : null;

        $parsed = parseLegacySubject($rawSubject, $rawChapter, $studentField);
        $targetGrade = $parsed['grade_hint'] ?? PersianTextNormalizer::gradeToLabel($studentGrade);
        $targetGroup = $parsed['group_hint'] ?? PersianTextNormalizer::fieldToGroupLabel($studentField);

        $lessonNode = null;
        foreach ($parsed['candidates'] as $cand) {
            $lessonNode = $currRepo->findLessonNode($cand, $targetGrade, $targetGroup);
            if ($lessonNode !== null) {
                break;
            }
        }

        // Second pass without group restriction (handles cases like طالب علمی in تجربی taking a general lesson)
        if ($lessonNode === null && $targetGroup !== null) {
            foreach ($parsed['candidates'] as $cand) {
                $lessonNode = $currRepo->findLessonNode($cand, $targetGrade, null);
                if ($lessonNode !== null) {
                    break;
                }
            }
        }

        // Third pass without grade restriction ONLY for unique high-school specialty lessons (e.g. گسسته -> دوازدهم)
        if ($lessonNode === null && $targetGrade !== null) {
            foreach ($parsed['candidates'] as $cand) {
                $lessonNode = $currRepo->findLessonNode($cand, null, $targetGroup);
                if ($lessonNode !== null) {
                    break;
                }
            }
        }

        $trimmedSubject = trim($rawSubject);
        $trimmedChapter = ($rawChapter !== null && trim($rawChapter) !== '') ? trim($rawChapter) : null;

        if ($lessonNode !== null) {
            $stats['matched_subject']++;
            $subjectId = (int) $lessonNode['id'];
            $canonicalSubject = mb_substr(
                CurriculumRepository::formatShortLessonLabel(
                    (string) $lessonNode['label'],
                    $lessonNode['grade_label'] ?? null
                ),
                0,
                128,
                'UTF-8'
            );

            $topicIds = [];
            if ($matchChapters && $trimmedChapter !== null) {
                $topicIds = matchUnambiguousChapters($trimmedChapter, $chaptersByLesson[$subjectId] ?? []);
                if ($topicIds !== []) {
                    $stats['matched_chapters']++;
                }
            }

            $plannedUpdates[] = [
                'id'           => $id,
                'old_subject'  => $rawSubject,
                'new_subject'  => $canonicalSubject,
                'subject_id'   => $subjectId,
                'topic_ids'    => $topicIds,
                'chapter'      => $trimmedChapter,
            ];
        } else {
            $stats['unmatched']++;
            $key = sprintf('%s (grade=%s, field=%s)', $rawSubject, (string) ($studentGrade ?? '?'), (string) ($studentField ?? '?'));
            $unmatchedSamples[$key] = ($unmatchedSamples[$key] ?? 0) + 1;

            // Even if unmatched, trim whitespace if dirty and initialize topic_ids_json to []
            if ($trimmedSubject !== $rawSubject || $row['topic_ids_json'] === null) {
                $stats['trimmed_only']++;
                $plannedUpdates[] = [
                    'id'          => $id,
                    'old_subject' => $rawSubject,
                    'new_subject' => $trimmedSubject !== '' ? mb_substr($trimmedSubject, 0, 128, 'UTF-8') : $rawSubject,
                    'subject_id'  => null,
                    'topic_ids'   => [],
                    'chapter'     => $trimmedChapter,
                ];
            }
        }
    }

    if ($apply && $plannedUpdates !== []) {
        $pdo->beginTransaction();
        try {
            $updStmt = $pdo->prepare(
                'UPDATE exam_results
                 SET subject_id = :subject_id,
                     topic_ids_json = :topic_ids_json,
                     subject = :subject,
                     chapter = :chapter
                 WHERE id = :id'
            );
            foreach ($plannedUpdates as $u) {
                $updStmt->execute([
                    ':subject_id'     => $u['subject_id'],
                    ':topic_ids_json' => json_encode($u['topic_ids'], JSON_UNESCAPED_UNICODE),
                    ':subject'        => $u['new_subject'],
                    ':chapter'        => $u['chapter'],
                    ':id'             => $u['id'],
                ]);
                if ($u['topic_ids'] !== []) {
                    ExamResult::syncResultTopics($pdo, $u['id'], $u['topic_ids']);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    arsort($unmatchedSamples);
    $report = [
        'stats'             => $stats,
        'updates_planned'   => count($plannedUpdates),
        'sample_mappings'   => array_slice($plannedUpdates, 0, 20),
        'unmatched_summary' => $unmatchedSamples,
    ];

    if ($asJson) {
        fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(0);
    }

    fwrite(STDOUT, sprintf(
        "[%s] Scanned: %d | Matched subject_id: %d | Matched chapters: %d | Unmatched: %d | Planned updates: %d\n",
        strtoupper($stats['mode']),
        $stats['scanned'],
        $stats['matched_subject'],
        $stats['matched_chapters'],
        $stats['unmatched'],
        count($plannedUpdates)
    ));

    if ($plannedUpdates !== []) {
        fwrite(STDOUT, "\nSample mappings (first 15):\n");
        foreach (array_slice($plannedUpdates, 0, 15) as $m) {
            fwrite(STDOUT, sprintf(
                "  #%d: '%s' -> subject_id=%s ('%s') topic_ids=%s\n",
                $m['id'],
                $m['old_subject'],
                $m['subject_id'] !== null ? (string) $m['subject_id'] : 'NULL',
                $m['new_subject'],
                json_encode($m['topic_ids'])
            ));
        }
    }

    if ($unmatchedSamples !== []) {
        fwrite(STDOUT, "\nUnmatched subjects (manual review):\n");
        foreach ($unmatchedSamples as $label => $cnt) {
            fwrite(STDOUT, "  - {$label} : {$cnt}\n");
        }
    }

    if (!$apply) {
        fwrite(STDOUT, "\nDry-run complete. Re-run with `--apply` to persist changes.\n");
    } else {
        fwrite(STDOUT, "\nApplied successfully.\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Backfill error: {$e->getMessage()}\n");
    exit(1);
}
