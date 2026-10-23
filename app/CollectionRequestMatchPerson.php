<?php

namespace App;

use App\Models\Adult;
use App\Models\Child;

enum CollectionRequestMatchPerson: string
{
    case Adult = 'adult';
    case Child = 'child';

    /** @return class-string<Adult>|class-string<Child> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Adult => Adult::class,
            self::Child => Child::class,
        };
    }

    public function nameColumn(): string
    {
        return match ($this) {
            self::Adult => 'requester_name_text',
            self::Child => 'requested_child_text',
        };
    }

    public function idColumn(): string
    {
        return match ($this) {
            self::Adult => 'adult_id',
            self::Child => 'requested_child_id',
        };
    }

    public function statusColumn(): string
    {
        return $this->value.'_match_status';
    }
}
