<?php

namespace App;

enum CollectionRequestStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Matched = 'matched';
    case NeedsApproval = 'needs_approval';
    case NoMatchFound = 'no_match_found';
    case MatchingFailed = 'matching_failed';

    public function isComplete(): bool
    {
        return ! in_array($this, [self::Pending, self::Processing], true);
    }
}
