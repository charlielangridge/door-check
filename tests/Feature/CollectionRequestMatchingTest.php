<?php

use App\CollectionRequestMatchPerson;
use App\CollectionRequestStatus;
use App\Jobs\MatchCollectionRequestAdultJob;
use App\Jobs\MatchCollectionRequestChildJob;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Services\CollectionRequestCandidateFinder;
use App\Settings\CollectionMatchingSettings;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Classification;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL test database.');
    }

    config(['ai.providers.typesafe.key' => 'testing-key']);
});

test('postgres discovers misspellings nicknames partial names and reversed names', function (string $name): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);

    $result = resolve(CollectionRequestCandidateFinder::class)->find(CollectionRequestMatchPerson::Adult, $name);

    expect(array_column($result['candidates'], 'id'))->toContain($adult->id);
    expect($result['truncated'])->toBeFalse();
})->with(['Elizabth Smth', 'Liz Smith', 'Liz', 'Smith Elizabeth']);

test('postgres binds untrusted names without executing their contents', function (): void {
    Adult::factory()->create(['name' => 'Robert Smith']);

    resolve(CollectionRequestCandidateFinder::class)->find(CollectionRequestMatchPerson::Adult, "Robert'); DROP TABLE adults; --");

    $this->assertDatabaseCount('adults', 1);
});

test('both matching jobs accept a unique related pair in either completion order', function (bool $childFirst): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $child = Child::factory()->create(['name' => 'Thomas Smith']);
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith', 'requested_child_text' => 'Tom Smith']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_fill_keys(array_keys($prompt->questions), new BooleanAnswer(0.97)))->preventStrayClassifications();
    $adultJob = new MatchCollectionRequestAdultJob($request->id);
    $childJob = new MatchCollectionRequestChildJob($request->id);

    app()->call([$childFirst ? $childJob : $adultJob, 'handle']);
    expect($request->fresh()->status)->toBe(CollectionRequestStatus::Processing);
    app()->call([$childFirst ? $adultJob : $childJob, 'handle']);

    expect($request->fresh())
        ->status->toBe(CollectionRequestStatus::Matched)
        ->adult_id->toBe($adult->id)
        ->requested_child_id->toBe($child->id)
        ->adult_match_status->toBe(CollectionRequestStatus::Matched)
        ->child_match_status->toBe(CollectionRequestStatus::Matched)
        ->adult_match_confidence->toBe(97.0)
        ->child_match_confidence->toBe(97.0)
        ->matching_threshold->toBe(95.0);
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->provider->name() === 'typesafe' && $prompt->model === 'jev-latest');
})->with([false, true]);

test('an individual match survives when the other person has no candidates', function (bool $childMatches): void {
    $person = $childMatches
        ? Child::factory()->create(['name' => 'Thomas Smith'])
        : Adult::factory()->create(['name' => 'Thomas Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => $childMatches ? 'ZZZZZZZZ' : 'Tom Smith', 'requested_child_text' => $childMatches ? 'Tom Smith' : 'ZZZZZZZZ']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_fill_keys(array_keys($prompt->questions), new BooleanAnswer(0.99)));

    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);
    app()->call([new MatchCollectionRequestChildJob($request->id), 'handle']);

    expect($request->fresh())->status->toBe(CollectionRequestStatus::NeedsApproval);
    $request->refresh();
    expect($request->getAttribute($childMatches ? 'requested_child_id' : 'adult_id'))->toBe($person->id);
    expect($request->getAttribute($childMatches ? 'adult_match_status' : 'child_match_status'))->toBe(CollectionRequestStatus::NoMatchFound);
})->with([false, true]);

test('independently identified people without a relationship require approval', function (): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $child = Child::factory()->create(['name' => 'Thomas Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith', 'requested_child_text' => 'Tom Smith']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_fill_keys(array_keys($prompt->questions), new BooleanAnswer(0.99)));

    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);
    app()->call([new MatchCollectionRequestChildJob($request->id), 'handle']);

    expect($request->fresh())->status->toBe(CollectionRequestStatus::NeedsApproval)->adult_id->toBe($adult->id)->requested_child_id->toBe($child->id);
    $this->assertDatabaseCount('adult_child', 0);
});

test('each individual must meet the stored threshold without rounding up', function (float $probability, float $threshold, CollectionRequestStatus $expected): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $settings = resolve(CollectionMatchingSettings::class);
    $settings->automatic_matching_threshold = $threshold;
    $settings->save();
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith']);
    Classification::fake([['candidate_'.$adult->id => new BooleanAnswer($probability)]])->preventStrayClassifications();

    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);

    expect($request->refresh())->adult_match_status->toBe($expected)->matching_threshold->toBe($threshold);
    expect($request->adult_match_candidates[0]['confidence'])->toEqual($probability * 100);
    expect($request->adult_match_confidence)->toBe($probability * 100);
    expect($request->adult_id)->toBe($expected === CollectionRequestStatus::Matched ? $adult->id : null);
})->with([
    'just below' => [0.949999, 95.0, CollectionRequestStatus::NeedsApproval],
    'at threshold' => [0.95, 95.0, CollectionRequestStatus::Matched],
    'changed setting' => [0.97, 98.0, CollectionRequestStatus::NeedsApproval],
    'fractional threshold is preserved' => [0.9500003, 95.001, CollectionRequestStatus::NeedsApproval],
]);

test('multiple qualifying related pairs remain ambiguous even if one scores higher', function (): void {
    $adults = Adult::factory()->count(2)->create(['name' => 'Elizabeth Smith']);
    $children = Child::factory()->count(2)->create(['name' => 'Thomas Smith']);
    foreach ($adults as $index => $adult) {
        $adult->children()->attach($children[$index], ['relationship' => 'parent']);
    }
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith', 'requested_child_text' => 'Tom Smith']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_combine(array_keys($prompt->questions), [new BooleanAnswer(0.97), new BooleanAnswer(0.96)]));

    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);
    app()->call([new MatchCollectionRequestChildJob($request->id), 'handle']);

    expect($request->refresh())->status->toBe(CollectionRequestStatus::NeedsApproval)->adult_id->toBeNull()->requested_child_id->toBeNull();
    expect($request->adult_match_candidates)->toHaveCount(2);
    expect($request->child_match_candidates)->toHaveCount(2);
});

test('truncated discovery never automatically accepts a shortlisted person', function (): void {
    config(['collection-matching.candidate_limit' => 1]);
    Adult::factory()->count(2)->create(['name' => 'Elizabeth Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_fill_keys(array_keys($prompt->questions), new BooleanAnswer(0.99)));

    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);

    expect($request->fresh())->adult_id->toBeNull()->adult_match_status->toBe(CollectionRequestStatus::NeedsApproval)->adult_candidates_truncated->toBeTrue();
});

test('empty registers produce no match without calling the provider or creating people', function (): void {
    $request = CollectionRequest::factory()->createQuietly();
    Classification::fake()->preventStrayClassifications();

    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);
    app()->call([new MatchCollectionRequestChildJob($request->id), 'handle']);

    expect($request->fresh())->status->toBe(CollectionRequestStatus::NoMatchFound)->adult_match_candidates->toBe([])->child_match_candidates->toBe([]);
    $this->assertDatabaseCount('adults', 0);
    $this->assertDatabaseCount('children', 0);
    Classification::assertNothingClassified();
});

test('completed individual matching is not repeated while the other job is pending', function (): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith']);
    Classification::fake([['candidate_'.$adult->id => new BooleanAnswer(0.99)]])->preventStrayClassifications();
    $job = new MatchCollectionRequestAdultJob($request->id);
    app()->call($job->handle(...));
    app()->call($job->handle(...));

    expect($request->fresh())->adult_id->toBe($adult->id)->adult_match_confidence->toBe(99.0);
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Liz Smith'));
});

test('both jobs use the same saved threshold even when settings change between jobs', function (): void {
    Adult::factory()->create(['name' => 'Elizabeth Smith']);
    Child::factory()->create(['name' => 'Thomas Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith', 'requested_child_text' => 'Tom Smith']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_fill_keys(array_keys($prompt->questions), new BooleanAnswer(0.96)));
    app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']);
    $settings = resolve(CollectionMatchingSettings::class);
    $settings->automatic_matching_threshold = 99;
    $settings->save();

    app()->call([new MatchCollectionRequestChildJob($request->id), 'handle']);

    expect($request->fresh())->matching_threshold->toBe(95.0)->child_match_status->toBe(CollectionRequestStatus::Matched);
});

test('provider connection failure leaves the other persons accepted match intact during retries and terminal failure', function (): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    Child::factory()->create(['name' => 'Thomas Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith', 'requested_child_text' => 'Tom Smith']);
    $request->forceFill(['adult_id' => $adult->id, 'adult_match_status' => CollectionRequestStatus::Matched, 'adult_match_confidence' => 99.0])->save();
    Http::preventStrayRequests();
    Http::fake(['api.typesafe.ai/v1/systemone' => Http::failedConnection()]);
    $job = new MatchCollectionRequestChildJob($request->id);

    expect(fn () => app()->call($job->handle(...)))->toThrow(ProviderConnectionException::class);

    expect($request->fresh())->status->toBe(CollectionRequestStatus::Processing)->child_match_status->toBe(CollectionRequestStatus::Processing)->adult_id->toBe($adult->id);
    $job->failed(new RuntimeException('Retries exhausted'));
    expect($request->fresh())->status->toBe(CollectionRequestStatus::MatchingFailed)->child_match_status->toBe(CollectionRequestStatus::MatchingFailed)->requested_child_id->toBeNull()->adult_id->toBe($adult->id)->adult_match_confidence->toBe(99.0);
    Http::assertSentCount(1);
});

test('invalid provider probabilities are retryable instead of being treated as no match', function (float $probability): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith']);
    Classification::fake([['candidate_'.$adult->id => new BooleanAnswer($probability)]])->preventStrayClassifications();

    expect(fn () => app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']))->toThrow(UnexpectedValueException::class);

    expect($request->fresh())->adult_id->toBeNull()->adult_match_status->toBe(CollectionRequestStatus::Processing)->adult_match_candidates->toBeNull();
})->with([-0.1, 1.1, INF, NAN]);

test('missing provider answers are retryable instead of being treated as no match', function (): void {
    Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith']);
    Classification::fake([new ClassificationResponse([], new TextUsage, new Meta('typesafe', 'jev-latest'))]);

    expect(fn () => app()->call([new MatchCollectionRequestAdultJob($request->id), 'handle']))->toThrow(InvalidArgumentException::class);

    expect($request->fresh())->adult_id->toBeNull()->adult_match_status->toBe(CollectionRequestStatus::Processing);
});

test('a provider retry can recover without redoing the accepted person', function (): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $child = Child::factory()->create(['name' => 'Thomas Smith']);
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->createQuietly(['requested_child_text' => 'Tom Smith']);
    $request->forceFill(['adult_id' => $adult->id, 'adult_match_status' => CollectionRequestStatus::Matched])->save();
    Http::preventStrayRequests();
    Http::fake(['api.typesafe.ai/v1/systemone' => Http::sequence()
        ->pushStatus(503)
        ->push(['answers' => ['candidate_'.$child->id => ['type' => 'noul', 'noul' => 0.97]]])]);
    $job = new MatchCollectionRequestChildJob($request->id);
    expect(fn () => app()->call($job->handle(...)))->toThrow(ProviderOverloadedException::class);

    app()->call($job->handle(...));

    expect($request->refresh())->status->toBe(CollectionRequestStatus::Matched)->adult_id->toBe($adult->id)->requested_child_id->toBe($child->id)->child_match_confidence->toBe(97.0);
    Http::assertSentCount(2);
});

test('a late failure callback cannot undo completed individual matching', function (): void {
    $adult = Adult::factory()->create(['name' => 'Elizabeth Smith']);
    $request = CollectionRequest::factory()->createQuietly(['requester_name_text' => 'Liz Smith']);
    Classification::fake([['candidate_'.$adult->id => new BooleanAnswer(0.99)]]);
    $job = new MatchCollectionRequestAdultJob($request->id);
    app()->call($job->handle(...));

    $job->failed(new RuntimeException('An older delivery failed'));

    expect($request->refresh())->adult_id->toBe($adult->id)->adult_match_status->toBe(CollectionRequestStatus::Matched);
});

test('concurrent adult and child jobs preserve both results and finish the request', function (): void {
    $databaseConfig = config('database.connections.pgsql');
    config(['database.connections.matching_concurrency' => $databaseConfig]);
    $adult = Adult::factory()->connection('matching_concurrency')->create(['name' => 'Elizabeth Smith']);
    $child = Child::factory()->connection('matching_concurrency')->create(['name' => 'Thomas Smith']);
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->connection('matching_concurrency')->createQuietly(['requester_name_text' => 'Liz Smith', 'requested_child_text' => 'Tom Smith']);
    $requestId = $request->id;
    $tasks = [];

    foreach ([CollectionRequestMatchPerson::Adult, CollectionRequestMatchPerson::Child] as $person) {
        $tasks[] = Closure::bind(static function () use ($databaseConfig, $requestId, $person): void {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $databaseConfig]);
            DB::purge('pgsql');
            Classification::fake(static function (ClassificationPrompt $prompt) use ($requestId, $person): array {
                DB::table('cache')->insert(['key' => 'matching-barrier:'.$requestId.':'.$person->value, 'value' => 'ready', 'expiration' => time() + 30]);
                $deadline = microtime(true) + 10;

                while (DB::table('cache')->where('key', 'like', 'matching-barrier:'.$requestId.':%')->count() < 2) {
                    throw_if(microtime(true) > $deadline, RuntimeException::class, 'Both matching jobs did not reach the concurrency barrier.');

                    Sleep::usleep(10000);
                }

                return array_fill_keys(array_keys($prompt->questions), new BooleanAnswer(0.99));
            })->preventStrayClassifications();

            $job = $person === CollectionRequestMatchPerson::Adult
                ? new MatchCollectionRequestAdultJob($requestId)
                : new MatchCollectionRequestChildJob($requestId);
            app()->call([$job, 'handle']);
        }, null, null);
    }

    try {
        Concurrency::driver('process')->run($tasks, timeout: 20);

        expect($request->refresh())->status->toBe(CollectionRequestStatus::Matched)->adult_id->toBe($adult->id)->requested_child_id->toBe($child->id)->adult_match_confidence->toBe(99.0)->child_match_confidence->toBe(99.0);
    } finally {
        $request->delete();
        $adult->children()->detach();
        $adult->delete();
        $child->delete();
        DB::connection('matching_concurrency')->table('cache')->where('key', 'like', 'matching-barrier:'.$requestId.':%')->delete();
        DB::disconnect('matching_concurrency');
    }
});
