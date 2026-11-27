<?php

declare(strict_types=1);

namespace App\Modules\Curriculum;

use App\Core\Database;
use PDO;

/**
 * Queries, enriches, and matches against the canonical `curriculum_nodes` tree (18,078 Lernito nodes).
 *
 * Depth semantics:
 *   - متوسطه 1 (no group level):
 *       level 1 = segment, level 2 = grade, level 3 = lesson, level 4 = chapter, level 5 = subtopic, level >= 6 = detail
 *   - متوسطه 2 (has group/field level):
 *       level 1 = segment, level 2 = group, level 3 = grade, level 4 = lesson, level 5 = chapter, level 6 = subtopic, level >= 7 = detail
 */
final class CurriculumRepository
{
    public const VALID_NODE_TYPES = ['root', 'segment', 'group', 'grade', 'lesson', 'chapter', 'subtopic', 'detail'];

    private PDO $db;

    /** @var array<int, array<int, array<string, mixed>>>|null Cached children by parent_id (0 = root) */
    private ?array $childrenIndex = null;

    /** @var array<int, array<string, mixed>>|null Cached nodes by id */
    private ?array $nodesById = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    public function clearMemoryCache(): void
    {
        $this->childrenIndex = null;
        $this->nodesById = null;
    }

    /**
     * Compute and persist `node_type`, `segment_id`, `lesson_id`, `grade_label`,
     * `group_label`, `full_path`, and `normalized_label` for every row in `curriculum_nodes`.
     *
     * @return array{total_nodes: int, updated: int, by_type: array<string, int>}
     */
    public function enrichAllNodes(): array
    {
        $rows = $this->db->query(
            'SELECT id, parent_id, label, level, sort_order FROM curriculum_nodes ORDER BY level ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ['total_nodes' => 0, 'updated' => 0, 'by_type' => []];
        }

        $byId = [];
        $children = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            $byId[$id] = [
                'id'         => $id,
                'parent_id'  => $parentId,
                'label'      => (string) $row['label'],
                'level'      => (int) $row['level'],
                'sort_order' => (int) $row['sort_order'],
            ];
            $children[$parentId ?? 0][] = $id;
        }

        // Detect which level-1 segments have a `group` level (متوسطه 2) vs direct `grade` level (متوسطه 1)
        $segmentHasGroup = [];
        foreach ($byId as $id => $node) {
            if ($node['level'] !== 1) {
                continue;
            }
            $normSeg = PersianTextNormalizer::looseKey($node['label']);
            if (str_contains($normSeg, 'متوسطه1')) {
                $segmentHasGroup[$id] = false;
                continue;
            }
            if (str_contains($normSeg, 'متوسطه2')) {
                $segmentHasGroup[$id] = true;
                continue;
            }
            // Fallback heuristic: inspect level-2 children labels
            $childIds = $children[$id] ?? [];
            $isGradeDirectly = false;
            foreach ($childIds as $cid) {
                if (PersianTextNormalizer::gradeToLabel($byId[$cid]['label'] ?? '') !== null) {
                    $isGradeDirectly = true;
                    break;
                }
            }
            $segmentHasGroup[$id] = !$isGradeDirectly;
        }

        $enriched = [];
        $byType = [];

        foreach ($byId as $id => $node) {
            // Walk ancestor chain root -> self
            $chain = [];
            $cur = $id;
            $guard = 0;
            while ($cur !== null && isset($byId[$cur]) && $guard < 20) {
                array_unshift($chain, $byId[$cur]);
                $cur = $byId[$cur]['parent_id'];
                $guard++;
            }

            $segmentId = null;
            foreach ($chain as $anc) {
                if ($anc['level'] === 1) {
                    $segmentId = $anc['id'];
                    break;
                }
            }

            $hasGroup = $segmentId !== null ? ($segmentHasGroup[$segmentId] ?? true) : true;
            $nodeType = self::classifyNodeType($node['level'], $hasGroup);

            $groupLabel = null;
            $gradeLabel = null;
            $lessonId = null;
            $lessonLabel = null;
            $topicParts = [];

            foreach ($chain as $anc) {
                $ancType = self::classifyNodeType($anc['level'], $hasGroup);
                $cleanLabel = PersianTextNormalizer::normalize($anc['label']);
                if ($ancType === 'group') {
                    $groupLabel = $cleanLabel;
                } elseif ($ancType === 'grade') {
                    $gradeLabel = $cleanLabel;
                } elseif ($ancType === 'lesson') {
                    $lessonId = $anc['id'];
                    $lessonLabel = $cleanLabel;
                } elseif (in_array($ancType, ['chapter', 'subtopic', 'detail'], true)) {
                    $topicParts[] = $cleanLabel;
                }
            }

            $fullPath = self::buildFullPath(
                nodeType: $nodeType,
                label: PersianTextNormalizer::normalize($node['label']),
                lessonLabel: $lessonLabel,
                gradeLabel: $gradeLabel,
                groupLabel: $groupLabel,
                topicParts: $topicParts
            );

            $enriched[$id] = [
                'id'               => $id,
                'node_type'        => $nodeType,
                'segment_id'       => $segmentId,
                'lesson_id'        => $lessonId,
                'grade_label'      => $gradeLabel !== null ? mb_substr($gradeLabel, 0, 32, 'UTF-8') : null,
                'group_label'      => $groupLabel !== null ? mb_substr($groupLabel, 0, 64, 'UTF-8') : null,
                'full_path'        => mb_substr($fullPath, 0, 500, 'UTF-8'),
                'normalized_label' => mb_substr(PersianTextNormalizer::normalize($node['label']), 0, 255, 'UTF-8'),
            ];
            $byType[$nodeType] = ($byType[$nodeType] ?? 0) + 1;
        }

        $updateStmt = $this->db->prepare(
            'UPDATE curriculum_nodes
             SET node_type = :node_type,
                 segment_id = :segment_id,
                 lesson_id = :lesson_id,
                 grade_label = :grade_label,
                 group_label = :group_label,
                 full_path = :full_path,
                 normalized_label = :normalized_label
             WHERE id = :id'
        );

        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }
        try {
            $updated = 0;
            foreach ($enriched as $row) {
                $updateStmt->execute([
                    ':node_type'        => $row['node_type'],
                    ':segment_id'       => $row['segment_id'],
                    ':lesson_id'        => $row['lesson_id'],
                    ':grade_label'      => $row['grade_label'],
                    ':group_label'      => $row['group_label'],
                    ':full_path'        => $row['full_path'],
                    ':normalized_label' => $row['normalized_label'],
                    ':id'               => $row['id'],
                ]);
                $updated++;
            }
            if ($startedTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->clearMemoryCache();

        return [
            'total_nodes' => count($rows),
            'updated'     => $updated,
            'by_type'     => $byType,
        ];
    }

    public static function classifyNodeType(int $level, bool $segmentHasGroup): string
    {
        if ($level <= 0) {
            return 'root';
        }
        if ($level === 1) {
            return 'segment';
        }
        if (!$segmentHasGroup) {
            return match ($level) {
                2 => 'grade',
                3 => 'lesson',
                4 => 'chapter',
                5 => 'subtopic',
                default => 'detail',
            };
        }
        return match ($level) {
            2 => 'group',
            3 => 'grade',
            4 => 'lesson',
            5 => 'chapter',
            6 => 'subtopic',
            default => 'detail',
        };
    }

    /**
     * Build a concise, human-friendly Persian path matching the admin UI convention:
     *   - lesson:   "زیست‌شناسی - دهم"
     *   - chapter:  "زیست‌شناسی - دهم، فصل 1: دنیای زنده"
     *   - subtopic: "زیست‌شناسی - دهم، فصل 1: دنیای زنده، گفتار 1: ..."
     *
     * @param array<int, string> $topicParts
     */
    public static function buildFullPath(
        string $nodeType,
        string $label,
        ?string $lessonLabel,
        ?string $gradeLabel,
        ?string $groupLabel,
        array $topicParts
    ): string {
        if ($nodeType === 'lesson') {
            $base = $label;
            if ($gradeLabel !== null) {
                $base .= ' - ' . $gradeLabel;
            }
            if ($groupLabel !== null) {
                $base .= ' (' . $groupLabel . ')';
            }
            return $base;
        }

        if (in_array($nodeType, ['chapter', 'subtopic', 'detail'], true) && $lessonLabel !== null) {
            $head = $lessonLabel . ($gradeLabel !== null ? ' - ' . $gradeLabel : '');
            return $topicParts !== [] ? ($head . '، ' . implode('، ', $topicParts)) : $head;
        }

        $parts = array_values(array_filter([$groupLabel, $gradeLabel, $label], static fn (?string $v): bool => $v !== null && $v !== ''));
        return $parts !== [] ? implode(' - ', array_unique($parts)) : $label;
    }

    /**
     * Deterministically resolve a Lernito question part's structured topic metadata
     * (`segment`, `group_name`, `grade`, `lesson`, `chapter`, `subject` [=subject0])
     * into exact `curriculum_nodes` IDs.
     *
     * Auto-suggestion depth cutoff (Option B): stops at `subtopic` (`subject0`),
     * NEVER descends to `detail` (`subject1`).
     *
     * @param array<string, mixed> $part
     * @return array{
     *   subject_id: ?int,
     *   subject_label: ?string,
     *   chapter_node_id: ?int,
     *   subtopic_node_id: ?int,
     *   suggested_topic_id: ?int,
     *   suggested_topic_label: ?string
     * }
     */
    public function resolveLernitoPartToNodes(array $part): array
    {
        $this->ensureTreeLoaded();

        $empty = [
            'subject_id'            => null,
            'subject_label'         => null,
            'chapter_node_id'       => null,
            'subtopic_node_id'      => null,
            'suggested_topic_id'    => null,
            'suggested_topic_label' => null,
        ];

        $segment = isset($part['segment']) ? trim((string) $part['segment']) : '';
        $group = isset($part['group_name']) ? trim((string) $part['group_name']) : (isset($part['group']) ? trim((string) $part['group']) : '');
        $grade = isset($part['grade']) ? trim((string) $part['grade']) : '';
        $lesson = isset($part['lesson']) ? trim((string) $part['lesson']) : '';
        $chapter = isset($part['chapter']) ? trim((string) $part['chapter']) : '';
        $subject0 = isset($part['subject']) ? trim((string) $part['subject']) : (isset($part['subject0']) ? trim((string) $part['subject0']) : '');

        if ($lesson === '' && $chapter === '') {
            return $empty;
        }

        // Step 1: Segment (level 1 under root 0)
        $segNode = $segment !== '' ? $this->findChildByLabel(0, $segment) : null;
        if ($segNode === null && $grade !== '') {
            // Infer segment from grade (هفتم/هشتم/نهم -> متوسطه 1, دهم/یازدهم/دوازدهم -> متوسطه 2)
            $gradeCanon = PersianTextNormalizer::gradeToLabel($grade);
            $targetSeg = in_array($gradeCanon, ['هفتم', 'هشتم', 'نهم'], true) ? 'متوسطه 1' : 'متوسطه 2';
            $segNode = $this->findChildByLabel(0, $targetSeg);
        }
        if ($segNode === null) {
            return $empty;
        }

        // Step 2: Optional group (متوسطه 2) -> Grade
        $parentForGrade = (int) $segNode['id'];
        $gradeNode = $grade !== '' ? $this->findChildByLabel($parentForGrade, $grade) : null;

        if ($gradeNode === null && $group !== '') {
            $groupNode = $this->findChildByLabel($parentForGrade, $group);
            if ($groupNode !== null) {
                $parentForGrade = (int) $groupNode['id'];
                $gradeNode = $grade !== '' ? $this->findChildByLabel($parentForGrade, $grade) : null;
            }
        }

        // Fallback: if group was missing in part metadata, scan all groups under segment for the grade+lesson
        $lessonNode = null;
        if ($gradeNode !== null && $lesson !== '') {
            $lessonNode = $this->findChildByLabel((int) $gradeNode['id'], $lesson);
        } elseif ($gradeNode === null && $grade !== '' && $lesson !== '') {
            foreach ($this->childrenIndex[$parentForGrade] ?? [] as $maybeGroup) {
                $gNode = $this->findChildByLabel((int) $maybeGroup['id'], $grade);
                if ($gNode !== null) {
                    $cand = $this->findChildByLabel((int) $gNode['id'], $lesson);
                    if ($cand !== null) {
                        $gradeNode = $gNode;
                        $lessonNode = $cand;
                        break;
                    }
                }
            }
        }

        if ($lessonNode === null) {
            return $empty;
        }

        $subjectId = (int) $lessonNode['id'];
        $subjectLabel = self::formatShortLessonLabel(
            (string) $lessonNode['label'],
            $gradeNode !== null ? (string) $gradeNode['label'] : ($grade !== '' ? $grade : null)
        );

        // Step 3: Chapter under lesson
        $chapterNode = $chapter !== '' ? $this->findChildByLabel($subjectId, $chapter) : null;
        $chapterNodeId = $chapterNode !== null ? (int) $chapterNode['id'] : null;

        // Step 4: Subtopic (subject0 / گفتار) under chapter — STOP HERE (never descend to subject1/detail)
        $subtopicNode = ($chapterNodeId !== null && $subject0 !== '')
            ? $this->findChildByLabel($chapterNodeId, $subject0)
            : null;
        $subtopicNodeId = $subtopicNode !== null ? (int) $subtopicNode['id'] : null;

        $suggestedNode = $subtopicNode ?? $chapterNode;
        $suggestedId = $suggestedNode !== null ? (int) $suggestedNode['id'] : null;
        $suggestedLabel = null;
        if ($subtopicNode !== null && $chapterNode !== null) {
            $suggestedLabel = PersianTextNormalizer::normalize((string) $chapterNode['label'])
                . '، '
                . PersianTextNormalizer::normalize((string) $subtopicNode['label']);
        } elseif ($chapterNode !== null) {
            $suggestedLabel = PersianTextNormalizer::normalize((string) $chapterNode['label']);
        }

        return [
            'subject_id'            => $subjectId,
            'subject_label'         => $subjectLabel,
            'chapter_node_id'       => $chapterNodeId,
            'subtopic_node_id'      => $subtopicNodeId,
            'suggested_topic_id'    => $suggestedId,
            'suggested_topic_label' => $suggestedLabel,
        ];
    }

    /**
     * Find a lesson node matching a student's grade/field and a canonical lesson name
     * (used by legacy exam_results backfill script and smart lookups).
     */
    public function findLessonNode(string $lessonName, ?string $gradeLabel, ?string $groupLabel = null): ?array
    {
        $this->ensureTreeLoaded();

        $lessonNorm = PersianTextNormalizer::normalize($lessonName);
        $lessonKey = PersianTextNormalizer::looseKey($lessonNorm);
        $lessonBaseKey = self::stripTrailingBookNumber($lessonKey);
        if ($lessonKey === '') {
            return null;
        }

        $targetGrade = $gradeLabel !== null ? PersianTextNormalizer::gradeToLabel($gradeLabel) : null;
        $targetGroup = $groupLabel !== null ? PersianTextNormalizer::fieldToGroupLabel($groupLabel) : null;

        $candidates = [];
        foreach ($this->nodesById ?? [] as $node) {
            if (($node['node_type'] ?? null) !== 'lesson') {
                continue;
            }
            $nodeKey = (string) ($node['loose_key'] ?? PersianTextNormalizer::looseKey((string) $node['label']));
            $nodeBaseKey = self::stripTrailingBookNumber($nodeKey);

            $matchScore = 0;
            if ($nodeKey === $lessonKey) {
                $matchScore = 100;
            } elseif ($lessonBaseKey !== '' && $nodeBaseKey === $lessonBaseKey) {
                $matchScore = 80;
            } elseif (mb_strlen($lessonBaseKey, 'UTF-8') >= 3 && str_starts_with($nodeKey, $lessonBaseKey)) {
                $matchScore = 50;
            } else {
                continue;
            }

            $score = $matchScore;
            if ($targetGrade !== null) {
                if (($node['grade_label'] ?? null) === $targetGrade) {
                    $score += 30;
                } elseif (($node['grade_label'] ?? null) !== null) {
                    continue; // Grade mismatch when grade is explicitly known
                }
            }
            if ($targetGroup !== null) {
                if (($node['group_label'] ?? null) === $targetGroup) {
                    $score += 15;
                } elseif (($node['group_label'] ?? null) !== null) {
                    $score -= 10;
                }
            }

            $candidates[] = ['score' => $score, 'node' => $node];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $candidates[0]['node'];
    }

    private static function stripTrailingBookNumber(string $looseKey): string
    {
        return preg_replace('/(?:\(?[123]\)?)$/u', '', $looseKey) ?? $looseKey;
    }

    /**
     * Smart autocomplete search for admin UI level-jumping & topic chip selection.
     *
     * @param array{
     *   q?: string,
     *   student_id?: ?int,
     *   subject_id?: ?int,
     *   parent_id?: ?int,
     *   node_types?: array<int, string>,
     *   limit?: int
     * } $params
     * @return array<int, array<string, mixed>>
     */
    public function autocomplete(array $params): array
    {
        $q = PersianTextNormalizer::normalize((string) ($params['q'] ?? ''));
        $limit = max(1, min(50, (int) ($params['limit'] ?? 25)));
        $parentId = isset($params['parent_id']) && $params['parent_id'] !== null ? (int) $params['parent_id'] : null;
        $subjectId = isset($params['subject_id']) && $params['subject_id'] !== null ? (int) $params['subject_id'] : null;
        $nodeTypes = [];
        foreach (($params['node_types'] ?? []) as $nt) {
            $t = strtolower(trim((string) $nt));
            if (in_array($t, self::VALID_NODE_TYPES, true)) {
                $nodeTypes[] = $t;
            }
        }

        $studentGrade = null;
        $studentGroup = null;
        if (!empty($params['student_id'])) {
            $stStmt = $this->db->prepare('SELECT grade, field FROM students WHERE id = :id LIMIT 1');
            $stStmt->execute([':id' => (int) $params['student_id']]);
            $st = $stStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($st)) {
                $studentGrade = PersianTextNormalizer::gradeToLabel($st['grade'] ?? null);
                $studentGroup = PersianTextNormalizer::fieldToGroupLabel($st['field'] ?? null);
            }
        }

        $where = ['1=1'];
        $binds = [];

        if ($parentId !== null) {
            if ($parentId === 0) {
                $where[] = 'cn.parent_id IS NULL';
            } else {
                $where[] = 'cn.parent_id = :parent_id';
                $binds[':parent_id'] = $parentId;
            }
        }

        if ($subjectId !== null && $subjectId > 0) {
            $where[] = 'cn.lesson_id = :subject_id AND cn.id <> :subject_self_id';
            $binds[':subject_id'] = $subjectId;
            $binds[':subject_self_id'] = $subjectId;
        }

        if ($nodeTypes !== []) {
            $typePh = [];
            foreach ($nodeTypes as $idx => $nt) {
                $k = ":nt_{$idx}";
                $typePh[] = $k;
                $binds[$k] = $nt;
            }
            $where[] = 'cn.node_type IN (' . implode(', ', $typePh) . ')';
        } elseif ($parentId === null && $subjectId === null) {
            // Default autocomplete returns actionable curriculum levels (lesson, chapter, subtopic, detail)
            $where[] = "(cn.node_type IS NULL OR cn.node_type IN ('lesson', 'chapter', 'subtopic', 'detail'))";
        }

        if ($q !== '') {
            $tokens = array_values(array_filter(explode(' ', $q), static fn (string $t): bool => $t !== ''));
            foreach (array_slice($tokens, 0, 5) as $idx => $tok) {
                $likeVal = '%' . $tok . '%';
                $k1 = ":q_lbl_{$idx}";
                $k2 = ":q_fp_{$idx}";
                $k3 = ":q_raw_{$idx}";
                $where[] = "(cn.normalized_label LIKE {$k1} OR cn.full_path LIKE {$k2} OR cn.label LIKE {$k3})";
                $binds[$k1] = $likeVal;
                $binds[$k2] = $likeVal;
                $binds[$k3] = $likeVal;
            }
        }

        // Priority boost for nodes matching the selected student's grade & field
        $orderExpr = 'cn.level ASC, cn.sort_order ASC, cn.id ASC';
        if ($studentGrade !== null || $studentGroup !== null) {
            $scoreParts = [];
            if ($studentGrade !== null) {
                $scoreParts[] = 'CASE WHEN cn.grade_label = :prio_grade THEN 20 ELSE 0 END';
                $binds[':prio_grade'] = $studentGrade;
            }
            if ($studentGroup !== null) {
                $scoreParts[] = 'CASE WHEN cn.group_label = :prio_group THEN 10 ELSE 0 END';
                $binds[':prio_group'] = $studentGroup;
            }
            $orderExpr = '(' . implode(' + ', $scoreParts) . ') DESC, ' . $orderExpr;
        }

        $sql = 'SELECT cn.id, cn.parent_id, cn.label, cn.level, cn.sort_order,
                       cn.node_type, cn.segment_id, cn.lesson_id, cn.grade_label, cn.group_label, cn.full_path,
                       EXISTS(SELECT 1 FROM curriculum_nodes child WHERE child.parent_id = cn.id LIMIT 1) AS has_children
                FROM curriculum_nodes cn
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY ' . $orderExpr . '
                LIMIT ' . $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($binds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'parent_id'    => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'label'        => (string) $r['label'],
                'level'        => (int) $r['level'],
                'sort_order'   => (int) $r['sort_order'],
                'node_type'    => $r['node_type'],
                'segment_id'   => $r['segment_id'] !== null ? (int) $r['segment_id'] : null,
                'lesson_id'    => $r['lesson_id'] !== null ? (int) $r['lesson_id'] : null,
                'grade_label'  => $r['grade_label'],
                'group_label'  => $r['group_label'],
                'full_path'    => $r['full_path'] ?: (string) $r['label'],
                'has_children' => (bool) ((int) ($r['has_children'] ?? 0)),
            ];
        }, $rows);
    }

    /**
     * Fetch multiple curriculum nodes by IDs, keyed by int id.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $v): bool => $v > 0
        )));
        if ($clean === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, parent_id, label, level, sort_order, node_type, segment_id, lesson_id, grade_label, group_label, full_path
             FROM curriculum_nodes
             WHERE id IN ({$placeholders})"
        );
        $stmt->execute($clean);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $map[$id] = [
                'id'          => $id,
                'parent_id'   => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'label'       => (string) $r['label'],
                'level'       => (int) $r['level'],
                'sort_order'  => (int) $r['sort_order'],
                'node_type'   => $r['node_type'],
                'segment_id'  => $r['segment_id'] !== null ? (int) $r['segment_id'] : null,
                'lesson_id'   => $r['lesson_id'] !== null ? (int) $r['lesson_id'] : null,
                'grade_label' => $r['grade_label'],
                'group_label' => $r['group_label'],
                'full_path'   => $r['full_path'] ?: (string) $r['label'],
            ];
        }
        return $map;
    }

    /**
     * Return full breadcrumb chain from root segment down to `$nodeId`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPath(int $nodeId): array
    {
        $path = [];
        $currentId = $nodeId;
        $guard = 0;

        $stmt = $this->db->prepare(
            'SELECT id, parent_id, label, level, node_type, lesson_id, grade_label, group_label, full_path
             FROM curriculum_nodes WHERE id = :id LIMIT 1'
        );

        while ($currentId !== null && $guard < 20) {
            $stmt->execute([':id' => $currentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                break;
            }
            array_unshift($path, [
                'id'          => (int) $row['id'],
                'parent_id'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'label'       => (string) $row['label'],
                'level'       => (int) $row['level'],
                'node_type'   => $row['node_type'],
                'lesson_id'   => $row['lesson_id'] !== null ? (int) $row['lesson_id'] : null,
                'grade_label' => $row['grade_label'],
                'group_label' => $row['group_label'],
                'full_path'   => $row['full_path'] ?: (string) $row['label'],
            ]);
            $currentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            $guard++;
        }

        return $path;
    }

    public static function formatShortLessonLabel(string $lessonLabel, ?string $gradeLabel): string
    {
        $cleanLesson = PersianTextNormalizer::normalize($lessonLabel);
        if ($gradeLabel === null || trim($gradeLabel) === '') {
            return $cleanLesson;
        }
        return $cleanLesson . ' - ' . PersianTextNormalizer::normalize($gradeLabel);
    }

    private function ensureTreeLoaded(): void
    {
        if ($this->childrenIndex !== null && $this->nodesById !== null) {
            return;
        }

        $rows = $this->db->query(
            'SELECT id, parent_id, label, level, sort_order, node_type, segment_id, lesson_id, grade_label, group_label, full_path
             FROM curriculum_nodes
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->childrenIndex = [];
        $this->nodesById = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $item = [
                'id'          => $id,
                'parent_id'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'label'       => (string) $row['label'],
                'norm_label'  => PersianTextNormalizer::normalize((string) $row['label']),
                'loose_key'   => PersianTextNormalizer::looseKey((string) $row['label']),
                'level'       => (int) $row['level'],
                'sort_order'  => (int) $row['sort_order'],
                'node_type'   => $row['node_type'],
                'segment_id'  => $row['segment_id'] !== null ? (int) $row['segment_id'] : null,
                'lesson_id'   => $row['lesson_id'] !== null ? (int) $row['lesson_id'] : null,
                'grade_label' => $row['grade_label'],
                'group_label' => $row['group_label'],
                'full_path'   => $row['full_path'],
            ];
            $this->nodesById[$id] = $item;
            $this->childrenIndex[$parentId][] = $item;
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    private function findChildByLabel(int $parentId, string $label): ?array
    {
        $children = $this->childrenIndex[$parentId] ?? [];
        if ($children === []) {
            return null;
        }

        $norm = PersianTextNormalizer::normalize($label);
        foreach ($children as $child) {
            if ($child['norm_label'] === $norm) {
                return $child;
            }
        }

        $loose = PersianTextNormalizer::looseKey($label);
        if ($loose !== '') {
            foreach ($children as $child) {
                if ($child['loose_key'] === $loose) {
                    return $child;
                }
            }
        }

        return null;
    }
}
