<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CollectionRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Matched = 'matched';
    case NeedsApproval = 'needs_approval';
    case NoMatchFound = 'no_match_found';
    case MatchingFailed = 'matching_failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Matched => 'Matched',
            self::NeedsApproval => 'Needs approval',
            self::NoMatchFound => 'No match found',
            self::MatchingFailed => 'Matching failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Matched => 'success',
            self::NeedsApproval => 'warning',
            self::NoMatchFound, self::MatchingFailed => 'danger',
        };
    }

    public function isComplete(): bool
    {
        return ! in_array($this, [self::Pending, self::Processing], true);
    }
}
