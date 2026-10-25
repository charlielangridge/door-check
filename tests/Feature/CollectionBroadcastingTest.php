<?php

use App\Events\CollectionBoardChanged;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;

beforeEach(function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'testing-key', 'broadcasting.connections.reverb.secret' => 'testing-secret', 'broadcasting.connections.reverb.app_id' => 'testing']);
    require base_path('routes/channels.php');
});

test('a private staff channel is restricted to the assigned venue and current daily session', function (): void {
    $site = Site::factory()->create();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-staff.'.$site->id])->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-staff.'.($site->id + 1)])->assertForbidden();
    $this->withSession(['staff_signed_in_at' => now()->subDays(2)->timestamp])->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-staff.'.$site->id])->assertForbidden();
});

test('the collecting browser can authorise its own private status channel without a user login', function (): void {
    $request = CollectionRequest::factory()->createQuietly(['browser_key_hash' => hash('sha256', str_repeat('a', 64))]);

    $this->withCookie('collection_browser', str_repeat('a', 64))->withCredentials()
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-collection.'.$request->uuid])
        ->assertOk()->assertJsonStructure(['auth']);

    $this->assertGuest();
});

test('verified admins can receive the selected venues staff updates without a kiosk assignment', function (): void {
    $site = Site::factory()->create();
    $admin = User::factory()->administrator()->globalCollector()->create();
    $this->actingAs($admin)->withSession(['staff_signed_in_at' => now()->timestamp])->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-staff.'.$site->id])->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-staff.'.($site->id + 100)])->assertForbidden();
    $admin->forceFill(['email_verified_at' => null])->save();
    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-staff.'.$site->id])->assertForbidden();
});

test('another browser cannot authorise a private collection channel', function (): void {
    $request = CollectionRequest::factory()->createQuietly(['browser_key_hash' => hash('sha256', str_repeat('a', 64))]);

    $this->withCookie('collection_browser', str_repeat('b', 64))->withCredentials()
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.1234', 'channel_name' => 'private-collection.'.$request->uuid])
        ->assertForbidden();
});

test('live invalidation messages contain no names photos or internal checks', function (): void {
    $event = new CollectionBoardChanged(5, 'request-uuid', true);

    expect($event->broadcastWith())->toBe(['request_uuid' => 'request-uuid', 'new_work' => true]);
    expect(array_map(fn ($channel): string => $channel->name, $event->broadcastOn()))->toBe(['private-staff.5', 'private-collection.request-uuid']);
});
