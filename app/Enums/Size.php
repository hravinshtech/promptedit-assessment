<?php

namespace App\Enums;

enum Size: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';

    public function outputTokens(Quality $quality): int
    {
        return match ($quality) {
            Quality::Low => match ($this) {
                self::Square => 280,
                self::Landscape, self::Portrait => 420,
            },
            Quality::Medium => match ($this) {
                self::Square => 1100,
                self::Landscape, self::Portrait => 1650,
            },
            Quality::High => match ($this) {
                self::Square => 4300,
                self::Landscape, self::Portrait => 6450,
            },
        };
    }
}
