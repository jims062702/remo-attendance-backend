<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Frame-count complexity of the tasks in an entry.
 *
 * From the operational note: "Since we are tracking mixed Task IDs, please
 * ensure that you correctly select the appropriate option under the complexity
 * field." Hence the explicit Mixed option -- a single entry frequently covers
 * task IDs of different sizes, and forcing one band would misreport it.
 */
enum TaskComplexity: string
{
    use HasEnumValues;

    case ShortFrame = 'short_frame';
    case SmallSceneFrame = 'small_scene_frame';
    case MidSceneFrames = 'mid_scene_frames';
    case LargeDense3k = 'large_dense_3k';
    case LargeDense4k = 'large_dense_4k';
    case LargeDense5k = 'large_dense_5k';
    case LargeDense6k = 'large_dense_6k';
    case LargeDense7k = 'large_dense_7k';
    case SuperDense8k = 'super_dense_8k';
    case Mixed = 'mixed';
    case EmptyQueue = 'empty_queue';

    public function label(): string
    {
        return match ($this) {
            self::ShortFrame => 'SHORT FRAME 1-499',
            self::SmallSceneFrame => 'SMALL SCENE FRAME 500-1K',
            self::MidSceneFrames => 'MID SCENE FRAMES 1K-2K+',
            self::LargeDense3k => 'LARGE FRAMES (HIGH DENSE 3K+)',
            self::LargeDense4k => 'LARGE FRAMES (HIGH DENSE 4K+)',
            self::LargeDense5k => 'LARGE FRAMES (HIGH DENSE 5K+)',
            self::LargeDense6k => 'LARGE FRAMES (HIGH DENSE 6K+)',
            self::LargeDense7k => 'LARGE FRAMES (HIGH DENSE 7K+)',
            self::SuperDense8k => 'SUPER DENSE FRAMES 8K+',
            self::Mixed => 'MIXED SHORT, SMALL, MEDIUM, AND LARGE FRAMES',
            self::EmptyQueue => 'EMPTY QUEUE',
        };
    }

    /**
     * Relative weight for effort-adjusted reporting. A super-dense 8K frame is
     * not one unit of the same thing as a 300-frame task, so raw task counts
     * across complexities are not comparable without this.
     *
     * Mixed takes a mid weight; empty queue produced nothing.
     */
    public function weight(): float
    {
        return match ($this) {
            self::ShortFrame => 1.0,
            self::SmallSceneFrame => 2.0,
            self::MidSceneFrames => 4.0,
            self::LargeDense3k => 6.0,
            self::LargeDense4k => 8.0,
            self::LargeDense5k => 10.0,
            self::LargeDense6k => 12.0,
            self::LargeDense7k => 14.0,
            self::SuperDense8k => 16.0,
            self::Mixed => 5.0,
            self::EmptyQueue => 0.0,
        };
    }
}
