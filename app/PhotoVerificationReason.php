<?php

namespace App;

enum PhotoVerificationReason: string
{
    case PersonallyKnown = 'personally_known';
    case CheckedInPerson = 'checked_in_person';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PersonallyKnown => 'Adult/child personally known to staff',
            self::CheckedInPerson => 'Identity checked in person',
            self::Other => 'Other',
        };
    }
}
