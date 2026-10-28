<?php

namespace App\Jobs;

use App\CollectionRequestMatchPerson;
use App\Models\CollectionRequest;
use App\Services\CollectionAudit;
use App\Services\CollectionRequestMatcher;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

abstract class MatchCollectionRequestPersonJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 55;

    public bool $failOnTimeout = false;

    public function __construct(public readonly int $collectionRequestId) {}

    abstract protected function person(): CollectionRequestMatchPerson;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('collection-request:'.$this->collectionRequestId.':'.$this->person()->value))
            ->releaseAfter(10)
            ->expireAfter(65)];
    }

    public function handle(CollectionRequestMatcher $matcher): void
    {
        try {
            $matcher->match($this->collectionRequestId, $this->person());
        } catch (Throwable $exception) {
            $request = CollectionRequest::query()->find($this->collectionRequestId);
            if ($request !== null) {
                resolve(CollectionAudit::class)->record('automatic_matching_attempt_failed', $request, ['person' => $this->person()->value, 'exception' => $exception::class]);
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        resolve(CollectionRequestMatcher::class)->fail($this->collectionRequestId, $this->person());
    }
}
