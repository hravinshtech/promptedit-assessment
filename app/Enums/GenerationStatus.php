<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    public function canTransitionTo(GenerationStatus $target): bool
    {
        if ($this === $target) {
            return true;
        }

        // Terminal states cannot be transitioned away from
        if ($this->isFinal()) {
            return false;
        }

        return match ($this) {
            self::Queued => in_array($target, [self::Processing, self::Succeeded, self::Failed], true),
            self::Processing => in_array($target, [self::Succeeded, self::Failed], true),
            default => false,
        };
    }
}
