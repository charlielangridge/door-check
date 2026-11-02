<?php

use App\Models\Adult;
use App\Models\Audit;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    $this->child = Child::factory()->hasAttached($this->site, [], 'sites')->create();
    $this->adult = Adult::factory()->create();
    $this->child->adults()->attach($this->adult, ['relationship' => 'Parent']);
    $this->staff = User::factory()->hasAttached($this->site, [], 'staffSites')->create();
    $this->staff->assignRole('staff');
    $this->actingAs($this->staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $this->childUrl = route('staff.children.update', ['site' => $this->site->slug, 'child' => $this->child->uuid]);
    $this->adultUrl = route('staff.children.adults.update', ['site' => $this->site->slug, 'child' => $this->child->uuid, 'adult' => $this->adult]);
    $this->details = ['name' => 'Updated Name', 'date_of_birth' => '2010-05-12', 'also_known_as' => ['Alternative Name']];
});

test('assigned staff can edit children and related adults including their relationship', function (): void {
    $this->patch($this->childUrl, $this->details)->assertRedirectToRoute('staff.children.index', $this->site->slug)->assertSessionHasNoErrors();
    expect($this->child->fresh())->name->toBe('Updated Name')->also_known_as->toBe(['Alternative Name']);
    expect($this->child->fresh()->date_of_birth->toDateString())->toBe('2010-05-12');

    $this->patch($this->adultUrl, [...$this->details, 'relationship' => 'Grandmother'])->assertRedirectToRoute('staff.children.index', $this->site->slug)->assertSessionHasNoErrors();
    expect($this->adult->fresh())->name->toBe('Updated Name')->also_known_as->toBe(['Alternative Name']);
    expect($this->adult->fresh()->date_of_birth->toDateString())->toBe('2010-05-12');
    expect($this->child->adults()->first()->pivot->relationship)->toBe('Grandmother');
    expect(Audit::query()->where('event', 'relationship_changed')->where('child_id', $this->child->id)->where('user_id', $this->staff->id)->exists())->toBeTrue();
});

test('staff can add a photo without deleting previous photos', function (string $personType): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $person = $this->{$personType};
    $oldPhoto = $person->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('photo', 'local');
    $oldLegacy = $person->addMedia(UploadedFile::fake()->image('legacy.jpg'))->toMediaCollection('logo', 'local');
    $details = [...$this->details, '_method' => 'patch', 'new_photo' => UploadedFile::fake()->image('new.jpg'), 'relationship' => 'Parent'];
    $this->post($this->{$personType.'Url'}, $details)->assertSessionHasNoErrors()->assertRedirect();

    $photos = $person->fresh()->getMedia('*');
    expect($photos)->toHaveCount(3);
    expect($photos->pluck('id')->all())->toContain($oldPhoto->id, $oldLegacy->id);
    Storage::disk('local')->assertExists($photos->sortBy('id')->last()->getPathRelativeToRoot());
    $this->get(route('staff.media.show', ['site' => $this->site->slug, 'media' => $photos->sortBy('id')->last()]))->assertOk();
})->with(['child', 'adult']);

test('empty aliases clear old aliases while leaving existing photo intact', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $this->child->update(['also_known_as' => ['Old Name']]);
    $photo = $this->child->addMedia(UploadedFile::fake()->image('child.jpg'))->toMediaCollection('photo', 'local');
    $this->patch($this->childUrl, ['name' => 'New Name', 'date_of_birth' => '2015-01-01'])->assertSessionHasNoErrors()->assertRedirect();
    expect($this->child->fresh()->also_known_as)->toBe([]);
    expect($this->child->fresh()->getFirstMedia('photo')->id)->toBe($photo->id);
});

test('invalid person details and photos cannot change records', function (array $invalid, string $field): void {
    $originalName = $this->child->name;
    $this->patch($this->childUrl, array_replace($this->details, $invalid))->assertInvalid($field);
    expect($this->child->fresh()->name)->toBe($originalName);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
    'future birth' => [['date_of_birth' => '2999-01-01'], 'date_of_birth'],
    'invalid birth' => [['date_of_birth' => 'invalid'], 'date_of_birth'],
    'invalid aliases' => [['also_known_as' => 'invalid'], 'also_known_as'],
    'invalid alias' => [['also_known_as' => [str_repeat('a', 256)]], 'also_known_as.0'],
    'too many aliases' => [['also_known_as' => array_map(fn (int $number): string => 'Name '.$number, range(1, 51))], 'also_known_as'],
    'file type' => [['new_photo' => UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')], 'new_photo'],
    'large photo' => [['new_photo' => UploadedFile::fake()->image('big.jpg')->size(2049)], 'new_photo'],
]);

test('adult relationship is required and cannot change another relationship', function (): void {
    $this->patch($this->adultUrl, [...$this->details, 'relationship' => ''])->assertInvalid('relationship');
    expect($this->child->adults()->first()->pivot->relationship)->toBe('Parent');
});

test('adult edits preserve relationships with other children and ignore unexpected identifiers', function (): void {
    $sibling = Child::factory()->create();
    $this->adult->children()->attach($sibling, ['relationship' => 'Guardian']);
    $originalChildUuid = $this->child->uuid;

    $this->patch($this->adultUrl, [...$this->details, 'relationship' => 'Grandmother', 'id' => 9999])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($this->adult->fresh()->id)->toBe($this->adult->id);
    expect($sibling->adults()->first()->pivot->relationship)->toBe('Guardian');

    $this->patch($this->childUrl, [...$this->details, 'uuid' => 'unexpected', 'id' => 9999])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($this->child->fresh()->uuid)->toBe($originalChildUuid);
    expect($this->child->fresh()->id)->toBe($this->child->id);
});

test('staff cannot edit children from other sites or adults unrelated to this child', function (): void {
    $otherChild = Child::factory()->create();
    $otherAdult = Adult::factory()->create();
    $otherChild->adults()->attach($otherAdult, ['relationship' => 'Parent']);
    $otherChildUrl = route('staff.children.update', ['site' => $this->site->slug, 'child' => $otherChild->uuid]);
    $otherAdultUrl = route('staff.children.adults.update', ['site' => $this->site->slug, 'child' => $this->child->uuid, 'adult' => $otherAdult]);
    $this->patch($otherChildUrl, $this->details)->assertNotFound();
    $this->patch($otherAdultUrl, [...$this->details, 'relationship' => 'Parent'])->assertNotFound();
    expect($otherChild->fresh()->name)->toBe($otherChild->name);
    expect($otherAdult->fresh()->name)->toBe($otherAdult->name);
});

test('staff cannot use an unassigned venue to edit people', function (): void {
    $otherSite = Site::factory()->create();
    $this->child->sites()->attach($otherSite);
    $this->patch(route('staff.children.update', ['site' => $otherSite->slug, 'child' => $this->child->uuid]), $this->details)->assertNotFound();
});

test('non staff users and expired sessions cannot edit people', function (): void {
    $this->actingAs(User::factory()->create())->patch($this->childUrl, $this->details)->assertForbidden();
    $this->actingAs($this->staff)->withSession(['staff_signed_in_at' => now()->subDays(2)->timestamp])
        ->patch($this->childUrl, $this->details)->assertRedirectToRoute('login');
});

test('guests cannot edit children or adults', function (): void {
    auth()->logout();
    $this->patch($this->childUrl, $this->details)->assertRedirectToRoute('login');
    $this->patch($this->adultUrl, $this->details)->assertRedirectToRoute('login');
});
