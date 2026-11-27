<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure normalizer turning raw Lernito shelf & question JSON documents into
 * canonical database rows for lernito_shelves, lernito_questions, and lernito_question_parts.
 */
final class QuestionNormalizer
{
    public const DESCRIPTIVE_TYPE = 'description';

    /**
     * Normalize raw shelf records into canonical shape preserving exact order.
     *
     * @param array<int, mixed> $records
     * @return array<int, array{position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>}>
     */
    public static function normalizeShelfRecords(array $records): array
    {
        $normalized = [];
        $position = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $qid = trim((string) ($record['_id'] ?? $record['question_lernito_id'] ?? $record['id'] ?? ''));
            if ($qid === '') {
                continue;
            }
            $docType = trim((string) ($record['docType'] ?? $record['doc_type'] ?? 'Question'));
            if ($docType === '') {
                $docType = 'Question';
            }

            $subParts = self::normalizeSubParts($record['subParts'] ?? $record['sub_parts'] ?? null);

            $normalized[] = [
                'position'            => $position,
                'question_lernito_id' => $qid,
                'doc_type'            => self::truncate($docType, 32) ?? 'Question',
                'sub_parts'           => $subParts,
            ];
            $position++;
        }

        return $normalized;
    }

    /**
     * Compute a deterministic SHA-256 hash over canonical shelf records (replaces unreliable dateModified).
     *
     * @param array<int, array{position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>}> $normalizedRecords
     */
    public static function computeRecordsHash(array $normalizedRecords): string
    {
        $canonical = [];
        foreach ($normalizedRecords as $rec) {
            $canonical[] = [
                'p' => (int) $rec['position'],
                'q' => (string) $rec['question_lernito_id'],
                't' => (string) $rec['doc_type'],
                's' => $rec['sub_parts'],
            ];
        }
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json !== false ? $json : '[]');
    }

    /**
     * Normalize a raw Lernito shelf document.
     *
     * @param array<string, mixed> $shelfDoc
     * @return array{
     *   shelf: array<string, mixed>,
     *   records: array<int, array{position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>}>
     * }
     */
    public static function normalizeShelf(array $shelfDoc, string $account): array
    {
        $lernitoId = trim((string) ($shelfDoc['_id'] ?? ''));
        if ($lernitoId === '') {
            throw new InvalidArgumentException('سند شلف لرنیتو فاقد شناسه _id است.');
        }

        $rawRecords = isset($shelfDoc['records']) && is_array($shelfDoc['records']) ? $shelfDoc['records'] : [];
        $records = self::normalizeShelfRecords($rawRecords);
        $recordsHash = self::computeRecordsHash($records);

        $name = trim((string) ($shelfDoc['name'] ?? $shelfDoc['title'] ?? ''));
        $color = self::nullableString($shelfDoc['color'] ?? null, 32);
        $creatorId = self::nullableString($shelfDoc['creatorId'] ?? null, 64);

        return [
            'shelf' => [
                'lernito_id'          => self::truncate($lernitoId, 64) ?? $lernitoId,
                'account'             => strtolower(trim($account)),
                'name'                => self::truncate($name, 255) ?? '',
                'color'               => $color,
                'creator_id'          => $creatorId,
                'record_count'        => count($records),
                'records_hash'        => $recordsHash,
                'lernito_created_at'  => self::parseIsoToUtc($shelfDoc['dateCreated'] ?? null),
                'lernito_modified_at' => self::parseIsoToUtc($shelfDoc['dateModified'] ?? null),
            ],
            'records' => $records,
        ];
    }

    /**
     * Normalize a raw Lernito question document into:
     *   - parent row for lernito_questions
     *   - normalized part rows for lernito_question_parts
     *
     * Rules:
     *   - Do NOT rely on `isSeparable`; rely on non-empty `subQuestions` and `count`.
     *   - Never physically discard `type = "description"` parts; store and mark `is_descriptive = 1`.
     *   - Topic priority: first topic with `isMainTopic === true`, fallback first topic, else NULL + `has_topic = 0`.
     *   - Store `subject0` in `subject` / `primary_subject` (while keeping complete `topics_json` including `subject1`).
     *
     * @param array<string, mixed> $doc
     * @return array{
     *   question: array<string, mixed>,
     *   parts: array<int, array<string, mixed>>
     * }
     */
    public static function normalizeQuestion(array $doc, ?string $sourceAccount = null): array
    {
        $lernitoId = trim((string) ($doc['_id'] ?? ''));
        if ($lernitoId === '') {
            throw new InvalidArgumentException('سند سوال لرنیتو فاقد شناسه _id است.');
        }

        $rawSubQuestions = isset($doc['subQuestions']) && is_array($doc['subQuestions'])
            ? array_values(array_filter($doc['subQuestions'], 'is_array'))
            : [];

        $isMultipart = count($rawSubQuestions) > 0;
        $rawType = trim((string) ($doc['type'] ?? ''));
        $questionType = $isMultipart
            ? 'multipart'
            : ($rawType !== '' ? (self::truncate($rawType, 32) ?? 'unknown') : 'unknown');

        $isParentDescriptive = !$isMultipart && self::isDescriptiveType($questionType);

        $declaredCount = isset($doc['count']) && is_numeric($doc['count']) ? (int) $doc['count'] : 1;
        $partCount = $isMultipart ? count($rawSubQuestions) : max(1, $declaredCount);

        $difficulty = null;
        if (isset($doc['difficulty']) && is_numeric($doc['difficulty'])) {
            $difficulty = max(0, min(255, (int) $doc['difficulty']));
        } elseif ($isMultipart && isset($rawSubQuestions[0]['difficulty']) && is_numeric($rawSubQuestions[0]['difficulty'])) {
            $difficulty = max(0, min(255, (int) $rawSubQuestions[0]['difficulty']));
        }

        $questionHtml = isset($doc['question']) && is_string($doc['question']) ? $doc['question'] : '';

        $rawTopics = isset($doc['topics']) && is_array($doc['topics']) ? array_values($doc['topics']) : null;
        $parts = [];

        if ($isMultipart) {
            foreach ($rawSubQuestions as $idx => $sub) {
                $subTypeRaw = trim((string) ($sub['type'] ?? ''));
                $subType = $subTypeRaw !== '' ? (self::truncate($subTypeRaw, 32) ?? 'unknown') : 'unknown';
                $subTopics = isset($sub['topics']) && is_array($sub['topics']) ? array_values($sub['topics']) : [];
                $selectedTopic = self::extractPrimaryTopic($subTopics);

                $parts[] = [
                    'part_index'     => $idx,
                    'part_type'      => $subType,
                    'is_descriptive' => self::isDescriptiveType($subType) ? 1 : 0,
                    'segment'        => $selectedTopic['segment'],
                    'grade'          => $selectedTopic['grade'],
                    'group_name'     => $selectedTopic['group'],
                    'lesson'         => $selectedTopic['lesson'],
                    'chapter'        => $selectedTopic['chapter'],
                    'subject'        => $selectedTopic['subject'],
                    'has_topic'      => $selectedTopic['has_topic'] ? 1 : 0,
                    'topics_json'    => $subTopics !== [] ? self::encodeJson($subTopics) : null,
                ];
            }
        } else {
            $singleTopics = $rawTopics ?? [];
            $selectedTopic = self::extractPrimaryTopic($singleTopics);

            $parts[] = [
                'part_index'     => 0,
                'part_type'      => $questionType,
                'is_descriptive' => $isParentDescriptive ? 1 : 0,
                'segment'        => $selectedTopic['segment'],
                'grade'          => $selectedTopic['grade'],
                'group_name'     => $selectedTopic['group'],
                'lesson'         => $selectedTopic['lesson'],
                'chapter'        => $selectedTopic['chapter'],
                'subject'        => $selectedTopic['subject'],
                'has_topic'      => $selectedTopic['has_topic'] ? 1 : 0,
                'topics_json'    => $singleTopics !== [] ? self::encodeJson($singleTopics) : null,
            ];
        }

        // Parent primary_* topic: prefer parent topics if present, else first part with a topic (fallback part 0)
        $parentTopic = self::extractPrimaryTopic($rawTopics ?? []);
        if (!$parentTopic['has_topic'] && $isMultipart) {
            foreach ($parts as $part) {
                if ((int) $part['has_topic'] === 1) {
                    $parentTopic = [
                        'has_topic' => true,
                        'segment'   => $part['segment'],
                        'group'     => $part['group_name'],
                        'grade'     => $part['grade'],
                        'lesson'    => $part['lesson'],
                        'chapter'   => $part['chapter'],
                        'subject'   => $part['subject'],
                    ];
                    break;
                }
            }
        }

        $version = isset($doc['version']) && is_numeric($doc['version'])
            ? max(0, (int) $doc['version'])
            : null;

        $questionRow = [
            'lernito_id'         => self::truncate($lernitoId, 64) ?? $lernitoId,
            'source_account'     => $sourceAccount !== null ? strtolower(trim($sourceAccount)) : null,
            'question_type'      => $questionType,
            'is_multipart'       => $isMultipart ? 1 : 0,
            'is_descriptive'     => $isParentDescriptive ? 1 : 0,
            'part_count'         => $partCount,
            'difficulty'         => $difficulty,
            'question_html'      => $questionHtml,
            'choices_json'       => isset($doc['choices']) && is_array($doc['choices']) ? self::encodeJson($doc['choices']) : null,
            'sub_questions_json' => $isMultipart ? self::encodeJson($rawSubQuestions) : null,
            'topics_json'        => $rawTopics !== null && $rawTopics !== [] ? self::encodeJson($rawTopics) : null,
            'references_json'    => isset($doc['references']) && is_array($doc['references']) ? self::encodeJson($doc['references']) : null,
            'flags_json'         => isset($doc['flags']) && is_array($doc['flags']) ? self::encodeJson($doc['flags']) : null,
            'has_topic'          => $parentTopic['has_topic'] ? 1 : 0,
            'primary_segment'    => $parentTopic['segment'],
            'primary_group'      => $parentTopic['group'],
            'primary_grade'      => $parentTopic['grade'],
            'primary_lesson'     => $parentTopic['lesson'],
            'primary_chapter'    => $parentTopic['chapter'],
            'primary_subject'    => $parentTopic['subject'],
            'lernito_version'    => $version,
            'lernito_created_by' => self::nullableString($doc['createdBy'] ?? null, 64),
            'lernito_created_at' => self::parseIsoToUtc($doc['dateCreated'] ?? null),
            'raw_json'           => self::encodeJson($doc),
        ];

        return [
            'question' => $questionRow,
            'parts'    => $parts,
        ];
    }

    /**
     * Extract primary topic fields from a topics array.
     * Priority: first topic with isMainTopic === true, fallback first topic.
     *
     * @param array<int, mixed> $topics
     * @return array{
     *   has_topic: bool,
     *   segment: ?string,
     *   group: ?string,
     *   grade: ?string,
     *   lesson: ?string,
     *   chapter: ?string,
     *   subject: ?string
     * }
     */
    public static function extractPrimaryTopic(array $topics): array
    {
        $valid = [];
        foreach ($topics as $item) {
            if (is_array($item) && $item !== []) {
                $valid[] = $item;
            }
        }

        if ($valid === []) {
            return [
                'has_topic' => false,
                'segment'   => null,
                'group'     => null,
                'grade'     => null,
                'lesson'    => null,
                'chapter'   => null,
                'subject'   => null,
            ];
        }

        $chosen = null;
        foreach ($valid as $candidate) {
            if (($candidate['isMainTopic'] ?? false) === true) {
                $chosen = $candidate;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = $valid[0];
        }

        $segment = self::nullableString($chosen['segment'] ?? null, 64);
        $group = self::nullableString($chosen['group'] ?? $chosen['group_name'] ?? null, 64);
        $grade = self::nullableString($chosen['grade'] ?? null, 64);
        $lesson = self::nullableString($chosen['lesson'] ?? null, 128);
        $chapter = self::nullableString($chosen['chapter'] ?? null, 255);
        $subject = self::nullableString($chosen['subject0'] ?? $chosen['subject'] ?? null, 255);

        $hasTopic = ($lesson !== null || $chapter !== null || $subject !== null || $grade !== null || $group !== null || $segment !== null);

        return [
            'has_topic' => $hasTopic,
            'segment'   => $segment,
            'group'     => $group,
            'grade'     => $grade,
            'lesson'    => $lesson,
            'chapter'   => $chapter,
            'subject'   => $subject,
        ];
    }

    public static function isDescriptiveType(?string $type): bool
    {
        return strtolower(trim((string) $type)) === self::DESCRIPTIVE_TYPE;
    }

    /**
     * Normalize subParts into a clean list of non-negative integers or null when absent.
     *
     * @return ?array<int, int>
     */
    public static function normalizeSubParts(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return null;
        }

        $result = [];
        foreach ($raw as $val) {
            if (is_int($val) || (is_string($val) && ctype_digit($val))) {
                $intVal = (int) $val;
                if ($intVal >= 0) {
                    $result[] = $intVal;
                }
            }
        }

        return $result;
    }

    public static function parseIsoToUtc(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable(trim($raw));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nullableString(mixed $raw, int $maxLength): ?string
    {
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }
        return self::truncate($trimmed, $maxLength);
    }

    private static function truncate(string $value, int $maxLength): ?string
    {
        if ($value === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }

    private static function encodeJson(mixed $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '{}';
    }
}
