<?php

return [
    'model' => env('COLLECTION_MATCHING_MODEL', 'jev-latest'),
    'timeout' => 20,
    'candidate_limit' => 25,
    'candidate_similarity_threshold' => 0.2,

    'nickname_groups' => [
        ['elizabeth', 'liz', 'lizzie', 'beth', 'betty'],
        ['william', 'will', 'bill', 'billy'],
        ['robert', 'rob', 'robbie', 'bob', 'bobby'],
        ['james', 'jim', 'jimmy', 'jamie'],
        ['richard', 'rich', 'rick', 'ricky', 'dick'],
        ['alexander', 'alex', 'alec'],
        ['katherine', 'catherine', 'kate', 'katie', 'kat', 'cathy'],
        ['margaret', 'maggie', 'meg', 'peggy'],
        ['thomas', 'tom', 'tommy'],
        ['michael', 'mike', 'mikey'],
        ['joseph', 'joe', 'joey'],
        ['rebecca', 'becca', 'becky'],
        ['susan', 'sue', 'susie'],
        ['jennifer', 'jen', 'jenny'],
        ['benjamin', 'ben', 'benji'],
        ['daniel', 'dan', 'danny'],
        ['samuel', 'samantha', 'sam', 'sammy'],
    ],
];
