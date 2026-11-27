<?php

declare(strict_types=1);

namespace Tests\Modules\Lernito;

use App\Modules\Lernito\ExamTopicCounter;
use App\Modules\Lernito\QuestionNormalizer;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Pure PHPUnit tests for ExamTopicCounter and QuestionNormalizer (zero DB dependency).
 */
final class ExamTopicCounterTest extends BaseTestCase
{
    private ExamTopicCounter $counter;

    /** @var array<string, mixed> */
    private array $fixtureData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->counter = new ExamTopicCounter();

        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/lernito_exam_topic_counter.json';
        $raw = file_get_contents($fixturePath);
        $this->assertIsString($raw, 'Shared fixture JSON must exist');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Shared fixture JSON must be valid');
        $this->fixtureData = $decoded;
    }

    public function testAllSharedFixtureCasesPass(): void
    {
        $cases = $this->fixtureData['cases'] ?? [];
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $name = (string) ($case['name'] ?? 'unnamed');
            $result = $this->counter->countFromRaw(
                $case['records'] ?? [],
                $case['questions'] ?? []
            );

            $exp = $case['expected'];
            $this->assertSame($exp['total_records'], $result['total_records'], "{$name}: total_records mismatch");
            $this->assertSame($exp['total_raw_units'], $result['total_raw_units'], "{$name}: total_raw_units (B) mismatch");
            $this->assertSame($exp['total_questions'], $result['total_questions'], "{$name}: total_questions (B-desc) mismatch");
            $this->assertSame($exp['skipped_descriptive'], $result['skipped_descriptive'], "{$name}: skipped_descriptive mismatch");
            $this->assertSame($exp['has_unclassified_topics'], $result['has_unclassified_topics'], "{$name}: has_unclassified_topics mismatch");
            $this->assertCount($exp['warnings_count'], $result['warnings'], "{$name}: warnings_count mismatch");

            if (isset($exp['unclassified_count'])) {
                $this->assertSame($exp['unclassified_count'], $result['unclassified_count'], "{$name}: unclassified_count mismatch");
            }
            if (isset($exp['warning_codes'])) {
                $actualCodes = array_map(static fn (array $w): string => $w['code'], $result['warnings']);
                $this->assertSame($exp['warning_codes'], $actualCodes, "{$name}: warning codes mismatch");
            }
            if (isset($exp['topics'])) {
                $this->assertSame($exp['topics'], $result['topics'], "{$name}: topics breakdown mismatch");
            }
        }
    }

    public function testSynthetic73RecordShelfMatchesB79AndBMinusDescriptive78(): void
    {
        $spec = $this->fixtureData['synthetic_scale_shelf'];
        $records = [];
        $questions = [];

        for ($i = 1; $i <= (int) $spec['single_mc_count']; $i++) {
            $id = sprintf('syn_scale_single_%04d', $i);
            $records[] = ['_id' => $id, 'docType' => 'Question'];
            $questions[$id] = [
                '_id'      => $id,
                'type'     => 'multipleChoice',
                'count'    => 1,
                'question' => "<p>Single {$i}</p>",
                'choices'  => ['A', 'B', 'C', 'D'],
                'topics'   => [[
                    'lesson'      => ($i <= 35) ? 'زیست‌شناسی' : 'شیمی',
                    'chapter'     => ($i <= 35) ? 'فصل 1' : 'فصل 2',
                    'subject0'    => "گفتار {$i}", // stored, NOT grouped
                    'isMainTopic' => true,
                ]],
            ];
        }

        for ($i = 1; $i <= (int) $spec['two_part_all_mc_count']; $i++) {
            $id = sprintf('syn_scale_multi_mc_%04d', $i);
            $records[] = ['_id' => $id, 'docType' => 'Question', 'subParts' => [0, 1]];
            $questions[$id] = [
                '_id'          => $id,
                'count'        => 2,
                'isSeparable'  => false,
                'question'     => "<p>Multi MC {$i}</p>",
                'subQuestions' => [
                    [
                        'type'     => 'multipleChoice',
                        'question' => '<p>Part 0</p>',
                        'choices'  => ['A', 'B'],
                        'topics'   => [['lesson' => 'فیزیک', 'chapter' => 'فصل 1', 'isMainTopic' => true]],
                    ],
                    [
                        'type'     => 'multipleChoice',
                        'question' => '<p>Part 1</p>',
                        'choices'  => ['A', 'B'],
                        'topics'   => [['lesson' => 'فیزیک', 'chapter' => 'فصل 1', 'isMainTopic' => true]],
                    ],
                ],
            ];
        }

        for ($i = 1; $i <= (int) $spec['two_part_mixed_desc_mc_count']; $i++) {
            $id = sprintf('syn_scale_mixed_%04d', $i);
            $records[] = ['_id' => $id, 'docType' => 'Question', 'subParts' => [0, 1]];
            $questions[$id] = [
                '_id'          => $id,
                'count'        => 2,
                'isSeparable'  => false,
                'question'     => "<p>Mixed {$i}</p>",
                'subQuestions' => [
                    [
                        'type'     => 'description',
                        'question' => '<p>Descriptive part 0</p>',
                        'topics'   => [['lesson' => 'ادبیات', 'chapter' => 'درس 2', 'isMainTopic' => true]],
                    ],
                    [
                        'type'     => 'multipleChoice',
                        'question' => '<p>Test part 1</p>',
                        'choices'  => ['A', 'B'],
                        'topics'   => [['lesson' => 'ادبیات', 'chapter' => 'درس 2', 'isMainTopic' => true]],
                    ],
                ],
            ];
        }

        $result = $this->counter->countFromRaw($records, $questions);

        $this->assertSame($spec['expected']['total_records'], $result['total_records']);
        $this->assertSame($spec['expected']['total_raw_units'], $result['total_raw_units']);
        $this->assertSame($spec['expected']['total_questions'], $result['total_questions']);
        $this->assertSame($spec['expected']['skipped_descriptive'], $result['skipped_descriptive']);
        $this->assertSame(0, $result['missing_question_count']);
        $this->assertSame([], $result['warnings']);
    }

    public function testNormalizerStoresSubject0AndFlagsDescriptiveWithoutDeleting(): void
    {
        $doc = [
            '_id'          => 'syn_norm_check_0001',
            'count'        => 2,
            'isSeparable'  => false,
            'question'     => '<p>Stem with $x^2$</p>',
            'subQuestions' => [
                [
                    'type'     => 'description',
                    'question' => '<p>Desc</p>',
                    'topics'   => [[
                        'segment'     => 'متوسطه 2',
                        'group'       => 'علوم تجربی',
                        'grade'       => 'یازدهم',
                        'lesson'      => 'زیست‌شناسی',
                        'chapter'     => 'فصل 3',
                        'subject0'    => 'گفتار 1',
                        'isMainTopic' => true,
                    ]],
                ],
                [
                    'type'     => 'multipleChoice',
                    'question' => '<p>MC</p>',
                    'choices'  => ['1', '2'],
                    'topics'   => [],
                ],
            ],
        ];

        $normalized = QuestionNormalizer::normalizeQuestion($doc, 'motevasete2');

        $this->assertSame(1, $normalized['question']['is_multipart']);
        $this->assertSame(2, $normalized['question']['part_count']);
        $this->assertSame('motevasete2', $normalized['question']['source_account']);
        $this->assertSame('گفتار 1', $normalized['question']['primary_subject']);

        // Both parts stored (descriptive is NOT physically deleted)
        $this->assertCount(2, $normalized['parts']);
        $this->assertSame(0, $normalized['parts'][0]['part_index']);
        $this->assertSame(1, $normalized['parts'][0]['is_descriptive']);
        $this->assertSame('گفتار 1', $normalized['parts'][0]['subject']);
        $this->assertSame(1, $normalized['parts'][0]['has_topic']);

        $this->assertSame(1, $normalized['parts'][1]['part_index']);
        $this->assertSame(0, $normalized['parts'][1]['is_descriptive']);
        $this->assertSame(0, $normalized['parts'][1]['has_topic']);
        $this->assertNull($normalized['parts'][1]['lesson']);
        $this->assertNull($normalized['parts'][1]['chapter']);
    }

    public function testRecordsHashChangesOnOrderOrSubPartsChange(): void
    {
        $r1 = QuestionNormalizer::normalizeShelfRecords([
            ['_id' => 'q1', 'docType' => 'Question', 'subParts' => [0, 1]],
            ['_id' => 'q2', 'docType' => 'Question'],
        ]);
        $r2 = QuestionNormalizer::normalizeShelfRecords([
            ['_id' => 'q1', 'docType' => 'Question', 'subParts' => [0]],
            ['_id' => 'q2', 'docType' => 'Question'],
        ]);
        $r3 = QuestionNormalizer::normalizeShelfRecords([
            ['_id' => 'q2', 'docType' => 'Question'],
            ['_id' => 'q1', 'docType' => 'Question', 'subParts' => [0, 1]],
        ]);

        $h1 = QuestionNormalizer::computeRecordsHash($r1);
        $h2 = QuestionNormalizer::computeRecordsHash($r2);
        $h3 = QuestionNormalizer::computeRecordsHash($r3);

        $this->assertSame(64, strlen($h1));
        $this->assertNotSame($h1, $h2);
        $this->assertNotSame($h1, $h3);
    }
}
