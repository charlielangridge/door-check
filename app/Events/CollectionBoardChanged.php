<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CollectionBoardChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly int $siteId, public readonly string $requestUuid, public readonly bool $newWork) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('staff.'.$this->siteId),
            new PrivateChannel('collection.'.$this->requestUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'collection.changed';
    }

    /** @return array{request_uuid: string, new_work: bool} */
    public function broadcastWith(): array
    {
        return ['request_uuid' => $this->requestUuid, 'new_work' => $this->newWork];
    }
}
