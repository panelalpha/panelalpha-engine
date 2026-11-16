<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The whole plan select() returns: which items it takes, which it skips and why, the
 * `bytes` it records on each, and the spendable total, in order.
 */
class HostPrewarmSelectionPinTest extends TestCase
{
    private const ITEMS = [
        ['id' => 'a', 'ref' => 'img/a:1', 'prewarm' => 10],
        ['id' => 'b', 'ref' => 'img/b:1', 'prewarm' => 20],
        ['id' => 'c', 'ref' => 'img/c:1', 'prewarm' => 30],
        ['id' => 'd', 'ref' => 'img/d:1', 'prewarm' => 40],
        ['id' => 'e', 'ref' => 'img/e:1', 'prewarm' => 50],
    ];

    private const SIZES = ['img/a:1' => 300, 'img/b:1' => 900, 'img/d:1' => 100];

    /**
     * @param array<string, int> $sizes
     * @param list<string> $present
     * @param array<string, mixed> $expected
     */
    #[DataProvider('plans')]
    public function test_the_plan_is_pinned(array $sizes, int $available, int $reserve, ?int $budget, array $present, array $expected): void
    {
        $this->assertSame($expected, HostPrewarmPlan::select(self::ITEMS, $sizes, $available, $reserve, $budget, $present));
    }

    public static function plans(): array
    {
        return [
            'a budget below the headroom' => [self::SIZES, 2000, 500, 700, ['img/e:1'], [
                'selected' => [
                    [
                        'id' => 'a',
                        'ref' => 'img/a:1',
                        'prewarm' => 10,
                        'bytes' => 300,
                    ],
                    [
                        'id' => 'c',
                        'ref' => 'img/c:1',
                        'prewarm' => 30,
                        'bytes' => null,
                    ],
                    [
                        'id' => 'd',
                        'ref' => 'img/d:1',
                        'prewarm' => 40,
                        'bytes' => 100,
                    ],
                ],
                'skipped' => [
                    [
                        'id' => 'b',
                        'ref' => 'img/b:1',
                        'prewarm' => 20,
                        'bytes' => 900,
                        'reason' => 'over budget',
                    ],
                    [
                        'id' => 'e',
                        'ref' => 'img/e:1',
                        'prewarm' => 50,
                        'reason' => 'already present',
                    ],
                ],
                'spendable' => 700,
            ]],
            'no budget: up to the reserve' => [self::SIZES, 1700, 500, null, [], [
                'selected' => [
                    [
                        'id' => 'a',
                        'ref' => 'img/a:1',
                        'prewarm' => 10,
                        'bytes' => 300,
                    ],
                    [
                        'id' => 'b',
                        'ref' => 'img/b:1',
                        'prewarm' => 20,
                        'bytes' => 900,
                    ],
                ],
                'skipped' => [
                    [
                        'id' => 'c',
                        'ref' => 'img/c:1',
                        'prewarm' => 30,
                        'bytes' => null,
                        'reason' => 'over budget',
                    ],
                    [
                        'id' => 'd',
                        'ref' => 'img/d:1',
                        'prewarm' => 40,
                        'bytes' => 100,
                        'reason' => 'over budget',
                    ],
                    [
                        'id' => 'e',
                        'ref' => 'img/e:1',
                        'prewarm' => 50,
                        'bytes' => null,
                        'reason' => 'over budget',
                    ],
                ],
                'spendable' => 1200,
            ]],
            'nothing left for an unmeasured item' => [['img/a:1' => 300], 800, 500, null, [], [
                'selected' => [
                    [
                        'id' => 'a',
                        'ref' => 'img/a:1',
                        'prewarm' => 10,
                        'bytes' => 300,
                    ],
                ],
                'skipped' => [
                    [
                        'id' => 'b',
                        'ref' => 'img/b:1',
                        'prewarm' => 20,
                        'bytes' => null,
                        'reason' => 'over budget',
                    ],
                    [
                        'id' => 'c',
                        'ref' => 'img/c:1',
                        'prewarm' => 30,
                        'bytes' => null,
                        'reason' => 'over budget',
                    ],
                    [
                        'id' => 'd',
                        'ref' => 'img/d:1',
                        'prewarm' => 40,
                        'bytes' => null,
                        'reason' => 'over budget',
                    ],
                    [
                        'id' => 'e',
                        'ref' => 'img/e:1',
                        'prewarm' => 50,
                        'bytes' => null,
                        'reason' => 'over budget',
                    ],
                ],
                'spendable' => 300,
            ]],
        ];
    }
}
