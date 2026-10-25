<?php

namespace App;

enum CollectionCancellationReason: string
{
    case AdultUnconfirmed = 'adult_unconfirmed';
    case ChildUnconfirmed = 'child_unconfirmed';
    case NotAuthorised = 'not_authorised';
    case AdultLeft = 'adult_left';
    case Duplicate = 'duplicate';
    case SubmittedInError = 'submitted_in_error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AdultUnconfirmed => 'Adult identity could not be confirmed',
            self::ChildUnconfirmed => 'Child identity could not be confirmed',
            self::NotAuthorised => 'Adult not authorised to collect',
            self::AdultLeft => 'Adult left / no longer collecting',
            self::Duplicate => 'Duplicate request',
            self::SubmittedInError => 'Request submitted in error',
            self::Other => 'Other',
        };
    }
}
