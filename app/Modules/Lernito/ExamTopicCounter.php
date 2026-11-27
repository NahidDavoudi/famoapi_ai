<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

/**
 * Pure PHP counter calculating per-(lesson, chapter) test question counts for a shelf/exam.
 *
 * Non-negotiable domain rules enforced here:
 *   1. Count ONLY non-descriptive questions (type !== "description").
 *   2. Order of operations for multipart records:
 *        FIRST apply `subParts` (0-based indices on raw `subQuestions`),
 *        THEN filter out parts where `type === "description"`.
 *   3. Multipart question referenced WITHOUT `subParts`:
 *        Include ALL `subQuestions` AND emit a structured warning.
 *   4. Each selected non-descriptive subQuestion counts as 1 unit.
 *      Reference exam total = B minus descriptive.
 *   5. Grouping granularity: `(lesson, chapter)` ONLY (`subject0` is stored in DB, not grouped).
 *   6. When `has_topic === false`: group under `lesson = 'نامشخص'`, `topic = null`,
 *      with explicit `has_topic = false` & `is_unclassified = true` flags on the row and
 *      `has_unclassified_topics = true` at the top level.
 */
final class ExamTopicCounter
{
    public const UNCLASSIFIED_LESSON = 'نامشخص';

    /**
     * Count topics directly from raw Lernito shelf records and raw question documents map.
     *
     * @param array<int, array<string, mixed>> $shelfRecords
     * @param array<string, array<string, mixed>> $rawQuestionsById Map of lernito_id => raw question doc
     * @return array{
     *   total_records: int,
     *   total_raw_units: int,
     *   total_questions: int,
     *   skipped_descriptive: int,
     *   missing_question_ids: array<int, string>,
     *   missing_question_count: int,
     *   has_unclassified_topics: bool,
     *   unclassified_count: int,
     *   warnings: array<int, array{code: string, question_id: string, position: int, message: string}>,
     *   topics: array<int, array{lesson: string, topic: ?string, count: int, has_topic: bool, is_unclassified: bool}>
     * }
     */
    public function countFromRaw(array $shelfRecords, array $rawQuestionsById): array
    {
        $normalizedRecords = QuestionNormalizer::normalizeShelfRecords($shelfRecords);
        $partsByQuestionId = [];

        foreach ($rawQuestionsById as $key => $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $qid = trim((string) ($doc['_id'] ?? $key));
            if ($qid === '') {
                continue;
            }
            $docWithId = $doc;
            $docWithId['_id'] = $qid;
            $normalized = QuestionNormalizer::normalizeQuestion($docWithId);
            $partsByQuestionId[$qid] = [
                'is_multipart' => (bool) $normalized['question']['is_multipart'],
                'parts'        => $normalized['parts'],
            ];
        }

        return $this->countFromNormalized($normalizedRecords, $partsByQuestionId);
    }

    /**
     * Count topics from already-normalized shelf records and lernito_question_parts rows.
     *
     * @param array<int, array{position: int, question_lernito_id: string, doc_type?: string, sub_parts: ?array<int, int>}> $records
     * @param array<string, array{is_multipart: bool, parts: array<int, array<string, mixed>>}> $questionsById
     * @return array{
     *   total_records: int,
     *   total_raw_units: int,
     *   total_questions: int,
     *   skipped_descriptive: int,
     *   missing_question_ids: array<int, string>,
     *   missing_question_count: int,
     *   has_unclassified_topics: bool,
     *   unclassified_count: int,
     *   warnings: array<int, array{code: string, question_id: string, position: int, message: string}>,
     *   topics: array<int, array{lesson: string, topic: ?string, count: int, has_topic: bool, is_unclassified: bool}>
     * }
     */
    public function countFromNormalized(array $records, array $questionsById): array
    {
        $totalRecords = count($records);
        $totalRawUnits = 0;
        $totalQuestions = 0;
        $skippedDescriptive = 0;
        $unclassifiedCount = 0;
        $missingQuestionIds = [];
        $warnings = [];
        $topicBuckets = [];

        foreach ($records as $index => $record) {
            $qid = trim((string) ($record['question_lernito_id'] ?? ''));
            $position = isset($record['position']) ? (int) $record['position'] : (int) $index;
            $subParts = QuestionNormalizer::normalizeSubParts($record['sub_parts'] ?? null);

            if ($qid === '' || !isset($questionsById[$qid])) {
                if ($qid !== '' && !in_array($qid, $missingQuestionIds, true)) {
                    $missingQuestionIds[] = $qid;
                }
                continue;
            }

            $qMeta = $questionsById[$qid];
            $isMultipart = (bool) ($qMeta['is_multipart'] ?? false);
            $partsByIndex = [];
            foreach (($qMeta['parts'] ?? []) as $part) {
                if (is_array($part)) {
                    $pIdx = (int) ($part['part_index'] ?? 0);
                    $partsByIndex[$pIdx] = $part;
                }
            }
            ksort($partsByIndex);

            if ($partsByIndex === []) {
                if (!in_array($qid, $missingQuestionIds, true)) {
                    $missingQuestionIds[] = $qid;
                }
                continue;
            }

            // STEP 1: Select raw part indices BEFORE checking `is_descriptive`.
            $selectedIndices = [];
            if ($isMultipart) {
                if ($subParts === null) {
                    // Rule 3: multipart without subParts => include all subQuestions + emit warning
                    $selectedIndices = array_keys($partsByIndex);
                    $warnings[] = [
                        'code'        => 'MULTIPART_WITHOUT_SUBPARTS',
                        'question_id' => $qid,
                        'position'    => $position,
                        'message'     => "سوال چندبخشی {$qid} در جایگاه {$position} بدون subParts ثبت شده است؛ تمام زیرسوال‌ها در نظر گرفته شدند.",
                    ];
                } else {
                    $seen = [];
                    foreach ($subParts as $rawIdx) {
                        if (isset($seen[$rawIdx])) {
                            continue;
                        }
                        $seen[$rawIdx] = true;
                        if (!array_key_exists($rawIdx, $partsByIndex)) {
                            $warnings[] = [
                                'code'        => 'SUBPART_OUT_OF_RANGE',
                                'question_id' => $qid,
                                'position'    => $position,
                                'message'     => "اندیس subParts ({$rawIdx}) برای سوال {$qid} خارج از محدوده زیرسوال‌هاست.",
                            ];
                            continue;
                        }
                        $selectedIndices[] = $rawIdx;
                    }
                }
            } else {
                // Single-part question always selects part_index 0
                $selectedIndices = array_key_exists(0, $partsByIndex)
                    ? [0]
                    : [array_key_first($partsByIndex)];
            }

            // STEP 2: Count raw units, THEN filter out descriptive parts.
            foreach ($selectedIndices as $pIdx) {
                $part = $partsByIndex[$pIdx];
                $totalRawUnits++;

                $isDescriptive = ((int) ($part['is_descriptive'] ?? 0) === 1)
                    || QuestionNormalizer::isDescriptiveType((string) ($part['part_type'] ?? ''));

                if ($isDescriptive) {
                    $skippedDescriptive++;
                    continue;
                }

                $totalQuestions++;

                $partHasTopic = ((int) ($part['has_topic'] ?? 0) === 1);
                $rawLesson = isset($part['lesson']) && is_string($part['lesson']) ? trim($part['lesson']) : '';
                $rawChapter = isset($part['chapter']) && is_string($part['chapter']) ? trim($part['chapter']) : '';

                // Grouping granularity (Decision 2): ONLY `chapter`; `subject0` is stored, NOT grouped.
                if (!$partHasTopic || ($rawLesson === '' && $rawChapter === '')) {
                    $lesson = self::UNCLASSIFIED_LESSON;
                    $topic = null;
                    $hasTopicFlag = false;
                    $unclassifiedCount++;
                } else {
                    $lesson = $rawLesson !== '' ? $rawLesson : self::UNCLASSIFIED_LESSON;
                    $topic = $rawChapter !== '' ? $rawChapter : null;
                    $hasTopicFlag = true;
                }

                $bucketKey = ($hasTopicFlag ? '1' : '0') . "\0" . $lesson . "\0" . ($topic ?? '');
                if (!isset($topicBuckets[$bucketKey])) {
                    $topicBuckets[$bucketKey] = [
                        'lesson'          => $lesson,
                        'topic'           => $topic,
                        'count'           => 0,
                        'has_topic'       => $hasTopicFlag,
                        'is_unclassified' => !$hasTopicFlag,
                    ];
                }
                $topicBuckets[$bucketKey]['count']++;
            }
        }

        // Keep classified topics in insertion order, with unclassified bucket at the end for clean UI display
        $classified = [];
        $unclassified = [];
        foreach ($topicBuckets as $bucket) {
            if ($bucket['is_unclassified']) {
                $unclassified[] = $bucket;
            } else {
                $classified[] = $bucket;
            }
        }
        $topics = array_merge($classified, $unclassified);

        return [
            'total_records'           => $totalRecords,
            'total_raw_units'         => $totalRawUnits,
            'total_questions'         => $totalQuestions,
            'skipped_descriptive'     => $skippedDescriptive,
            'missing_question_ids'    => $missingQuestionIds,
            'missing_question_count'  => count($missingQuestionIds),
            'has_unclassified_topics' => $unclassifiedCount > 0,
            'unclassified_count'      => $unclassifiedCount,
            'warnings'                => $warnings,
            'topics'                  => $topics,
        ];
    }
}
