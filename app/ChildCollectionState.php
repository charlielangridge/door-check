<?php

namespace App;

enum ChildCollectionState: string
{
    case NeedsChecking = 'needs_checking';
    case Reviewed = 'reviewed';
    case Preparing = 'preparing';
    case Collected = 'collected';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Collected, self::Cancelled], true);
    }

    public function label(bool $public = false): string
    {
        return match ($this) {
            self::NeedsChecking => $public ? 'Request received' : 'Needs checking',
            self::Reviewed => $public ? 'Request reviewed' : 'Reviewed',
            self::Preparing => 'Preparing child',
            self::Collected => 'Child collected',
            self::Cancelled => $public ? 'Collection cancelled — please speak to staff' : 'Cancelled',
        };
    }
}
