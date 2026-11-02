<?php

use App\Models\Adult;
use App\Models\Audit;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    $this->staff = User::factory()->hasAttached($this->site, [], 'staffSites')->create();
    $this->staff->assignRole('staff');
    $this->actingAs($this->staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $this->url = route('staff.children.store', $this->site->slug);
    $this->payload = [
        'name' => 'Jamie Registration', 'date_of_birth' => '2019-04-12', 'also_known_as' => ['Jamie Example'],
        'adults' => [['mode' => 'new', 'name' => 'Morgan Registration', 'date_of_birth' => '1980-05-01', 'relationship' => 'Parent', 'also_known_as' => ['Morgan Example']]],
    ];
});

test('assigned staff register a child with new and existing adults and select the saved child', function (): void {
    $existing = Adult::factory()->hasAttached($this->site, [], 'sites')->create();
    $this->payload['adults'][] = ['mode' => 'existing', 'adult_id' => $existing->id, 'relationship' => 'Grandparent', 'name' => 'Do not change this adult'];
    $response = $this->post($this->url, $this->payload)->assertSessionHasNoErrors()->assertRedirectToRoute('staff.children.index', $this->site->slug);
    $child = Child::query()->where('name', 'Jamie Registration')->sole();
    $newAdult = Adult::query()->where('name', 'Morgan Registration')->sole();
    expect($child->sites()->sole()->id)->toBe($this->site->id);
    expect($child->also_known_as)->toBe(['Jamie Example']);
    expect($newAdult->also_known_as)->toBe(['Morgan Example']);
    expect($newAdult->sites()->sole()->id)->toBe($this->site->id);
    expect($child->adults()->count())->toBe(2);
    expect($child->adults()->whereKey($newAdult->id)->first()->pivot->relationship)->toBe('Parent');
    expect($child->adults()->whereKey($existing->id)->first()->pivot->relationship)->toBe('Grandparent');
    expect($existing->fresh()->name)->toBe($existing->name);
    $response->assertSessionHas('registered_child_uuid', $child->uuid);
    expect(Audit::query()->where('event', 'relationship_attached')->where('child_id', $child->id)->where('user_id', $this->staff->id)->exists())->toBeTrue();
    $this->get(route('staff.children.index', $this->site->slug))->assertInertia(fn (Assert $page) => $page
        ->component('Staff/Child/Index')->where('registeredChildUuid', $child->uuid));
});

test('registration saves optional child and adult photos with private access', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $this->payload['new_photo'] = UploadedFile::fake()->image('child.jpg');
    $this->payload['adults'][0]['new_photo'] = UploadedFile::fake()->image('adult.jpg');
    $this->post($this->url, $this->payload)->assertSessionHasNoErrors()->assertRedirect();
    $child = Child::query()->sole();
    $adult = $child->adults()->sole();
    foreach ([$child, $adult] as $person) {
        $photo = $person->getFirstMedia('logo');
        expect($photo)->not->toBeNull();
        Storage::disk('local')->assertExists($photo->getPathRelativeToRoot());
        $this->get(route('staff.media.show', ['site' => $this->site->slug, 'media' => $photo]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
});

test('invalid registrations save no people or relationships', function (array $changes, string $error): void {
    $payload = array_replace_recursive($this->payload, $changes);
    if (array_key_exists('adults', $changes) && $changes['adults'] === []) {
        $payload['adults'] = [];
    }
    $this->post($this->url, $payload)->assertInvalid($error);
    expect(Child::query()->count())->toBe(0);
    expect(Adult::query()->count())->toBe(0);
    $this->assertDatabaseCount('adult_child', 0);
})->with([
    'name required' => [['name' => ''], 'name'],
    'birth required' => [['date_of_birth' => ''], 'date_of_birth'],
    'future birth' => [['date_of_birth' => '2999-01-01'], 'date_of_birth'],
    'adult required' => [['adults' => []], 'adults'],
    'adult name' => [['adults' => [['name' => '']]], 'adults.0.name'],
    'adult birth' => [['adults' => [['date_of_birth' => 'invalid']]], 'adults.0.date_of_birth'],
    'relationship required' => [['adults' => [['relationship' => '']]], 'adults.0.relationship'],
    'adult mode' => [['adults' => [['mode' => 'unknown']]], 'adults.0.mode'],
    'existing adult required' => [['adults' => [['mode' => 'existing']]], 'adults.0.adult_id'],
    'child aliases' => [['also_known_as' => 'invalid'], 'also_known_as'],
    'adult aliases' => [['adults' => [['also_known_as' => 'invalid']]], 'adults.0.also_known_as'],
    'child photo type' => [['new_photo' => UploadedFile::fake()->create('bad.pdf', 10)], 'new_photo'],
    'adult photo size' => [['adults' => [['new_photo' => UploadedFile::fake()->image('large.jpg')->size(2049)]]], 'adults.0.new_photo'],
]);

test('matching child and adult names and births require explicit duplicate choices', function (): void {
    $child = Child::factory()->hasAttached($this->site, [], 'sites')->create(['name' => mb_strtoupper($this->payload['name']), 'date_of_birth' => $this->payload['date_of_birth']]);
    $adult = Adult::factory()->hasAttached($this->site, [], 'sites')->create(['name' => $this->payload['adults'][0]['name'], 'date_of_birth' => $this->payload['adults'][0]['date_of_birth']]);
    $this->from(route('staff.children.index', $this->site->slug))->post($this->url, $this->payload)
        ->assertInvalid(['create_different', 'adults.0.create_different'])
        ->assertSessionHas('registration_duplicates.child.people.0.uuid', $child->uuid)
        ->assertSessionHas('registration_duplicates.adults.0.people.0.id', $adult->id);
    expect(Child::query()->count())->toBe(1);
    expect(Adult::query()->count())->toBe(1);
    $this->get(route('staff.children.index', $this->site->slug))->assertInertia(fn (Assert $page) => $page
        ->component('Staff/Child/Index')->where('registrationDuplicates.child.people.0.uuid', $child->uuid)
        ->where('registrationDuplicates.adults.0.people.0.id', $adult->id));

    $this->payload['create_different'] = true;
    $this->payload['adults'][0]['create_different'] = true;
    $this->post($this->url, $this->payload)->assertSessionHasNoErrors()->assertRedirect();
    expect(Child::query()->count())->toBe(2);
    expect(Adult::query()->count())->toBe(2);
});

test('staff can resolve a duplicate by linking the existing adult', function (): void {
    $adult = Adult::factory()->hasAttached($this->site, [], 'sites')->create(['name' => $this->payload['adults'][0]['name'], 'date_of_birth' => $this->payload['adults'][0]['date_of_birth']]);
    $this->payload['adults'] = [['mode' => 'existing', 'adult_id' => $adult->id, 'relationship' => 'Parent']];
    $this->post($this->url, $this->payload)->assertSessionHasNoErrors()->assertRedirect();
    expect(Adult::query()->count())->toBe(1);
    expect(Child::query()->sole()->adults()->sole()->id)->toBe($adult->id);
});

test('same name with different birth does not block registration and other venues do not leak duplicates', function (): void {
    Child::factory()->hasAttached($this->site, [], 'sites')->create(['name' => $this->payload['name'], 'date_of_birth' => '2018-01-01']);
    Adult::factory()->hasAttached($this->site, [], 'sites')->create(['name' => $this->payload['adults'][0]['name'], 'date_of_birth' => '1970-01-01']);
    Child::factory()->create(['name' => $this->payload['name'], 'date_of_birth' => $this->payload['date_of_birth']]);
    Adult::factory()->create(['name' => $this->payload['adults'][0]['name'], 'date_of_birth' => $this->payload['adults'][0]['date_of_birth']]);
    $this->post($this->url, $this->payload)->assertSessionHasNoErrors()->assertRedirect();
});

test('registration failure rolls back people memberships relationships and photo files', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $otherAdult = Adult::factory()->create();
    $this->payload['new_photo'] = UploadedFile::fake()->image('child.jpg');
    $this->payload['adults'][0]['new_photo'] = UploadedFile::fake()->image('adult.jpg');
    $this->payload['adults'][] = ['mode' => 'existing', 'adult_id' => $otherAdult->id, 'relationship' => 'Parent'];
    $this->post($this->url, $this->payload)->assertNotFound();
    expect(Child::query()->count())->toBe(0);
    expect(Adult::query()->count())->toBe(1);
    $this->assertDatabaseCount('adult_child', 0);
    $this->assertDatabaseCount('site_child', 0);
    $this->assertDatabaseCount('site_adult', 0);
    $this->assertDatabaseCount('media', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('existing adults cannot be linked twice in one registration', function (): void {
    $adult = Adult::factory()->hasAttached($this->site, [], 'sites')->create();
    $this->payload['adults'] = array_fill(0, 2, ['mode' => 'existing', 'adult_id' => $adult->id, 'relationship' => 'Parent']);
    $this->post($this->url, $this->payload)->assertInvalid('adults.1.adult_id');
    expect(Child::query()->count())->toBe(0);
});

test('adult searches return only current venue adults and private photo urls', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $adult = Adult::factory()->hasAttached($this->site, [], 'sites')->create(['name' => 'Morgan Search']);
    $photo = $adult->addMedia(UploadedFile::fake()->image('adult.jpg'))->toMediaCollection('logo', 'local');
    Adult::factory()->create(['name' => 'Morgan Other Venue']);
    $url = route('staff.registration.adults', ['site' => $this->site->slug, 'q' => 'MORGAN']);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'adults')->assertJsonPath('adults.0.id', $adult->id)
        ->assertJsonPath('adults.0.photo', route('staff.media.show', ['site' => $this->site->slug, 'media' => $photo]));
    $this->getJson(route('staff.registration.adults', ['site' => $this->site->slug, 'q' => 'M']))->assertUnprocessable();
});

test('unassigned venues non staff guests and expired sessions cannot register or search adults', function (): void {
    $otherSite = Site::factory()->create();
    $this->post(route('staff.children.store', $otherSite->slug), $this->payload)->assertNotFound();
    $this->getJson(route('staff.registration.adults', ['site' => $otherSite->slug, 'q' => 'Adult']))->assertNotFound();
    $this->actingAs(User::factory()->create())->post($this->url, $this->payload)->assertForbidden();
    $this->actingAs($this->staff)->withSession(['staff_signed_in_at' => now()->subDays(2)->timestamp])->post($this->url, $this->payload)->assertRedirectToRoute('login');
    auth()->logout();
    $this->post($this->url, $this->payload)->assertRedirectToRoute('login');
    $this->get(route('staff.registration.adults', ['site' => $this->site->slug, 'q' => 'Adult']))->assertRedirectToRoute('login');
    expect(Child::query()->count())->toBe(0);
});
