<?php

namespace App\Jobs;

use App\CollectionRequestMatchPerson;

class MatchCollectionRequestChildJob extends MatchCollectionRequestPersonJob
{
    protected function person(): CollectionRequestMatchPerson
    {
        return CollectionRequestMatchPerson::Child;
    }
}
