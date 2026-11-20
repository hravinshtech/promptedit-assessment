<?php

namespace App\Enums;

enum Quality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function reasoningTokenRange(): array
    {
        return match ($this) {
            self::Low => ['min' => 0, 'max' => 500, 'estimate' => 250],
            self::Medium => ['min' => 200, 'max' => 2000, 'estimate' => 1100],
            self::High => ['min' => 1000, 'max' => 8000, 'estimate' => 4500],
        };
    }
}
