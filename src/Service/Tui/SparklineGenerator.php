<?php

declare(strict_types=1);

namespace App\Service\Tui;

class SparklineGenerator
{
    private const TICKS = [' ', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @param float[] $values
     */
    public static function generate(array $values, int $maxTicks = 30): string
    {
        if (empty($values)) {
            return str_repeat('─', $maxTicks);
        }

        // Slice to max length
        if (count($values) > $maxTicks) {
            $values = array_slice($values, -$maxTicks);
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        $chars = '';
        $tickCount = count(self::TICKS);

        foreach ($values as $val) {
            if ($range <= 0.0001) {
                $chars .= self::TICKS[3]; // Middle tick if all values are flat
                continue;
            }

            $norm = ($val - $min) / $range;
            $index = (int)floor($norm * ($tickCount - 1));
            $index = max(0, min($tickCount - 1, $index));
            $chars .= self::TICKS[$index];
        }

        return $chars;
    }
}
