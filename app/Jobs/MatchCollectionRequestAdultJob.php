<?php

namespace App\Jobs;

use App\CollectionRequestMatchPerson;

class MatchCollectionRequestAdultJob extends MatchCollectionRequestPersonJob
{
    protected function person(): CollectionRequestMatchPerson
    {
        return CollectionRequestMatchPerson::Adult;
    }
}
