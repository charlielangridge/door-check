<?php

use App\Filament\Pages\ManageCollectionMatching;
use App\Models\User;
use App\Settings\CollectionMatchingSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());
});

test('the settings page is discovered and saves the matching threshold', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(ManageCollectionMatching::class);

    Livewire::test(ManageCollectionMatching::class)
        ->assertOk()
        ->fillForm(['automatic_matching_threshold' => 97.5])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(resolve(CollectionMatchingSettings::class)->automatic_matching_threshold)->toBe(97.5);
});

test('the settings page rejects invalid thresholds without changing the saved value', function (mixed $value, string $rule): void {
    $original = resolve(CollectionMatchingSettings::class)->automatic_matching_threshold;

    Livewire::test(ManageCollectionMatching::class)
        ->fillForm(['automatic_matching_threshold' => $value])
        ->call('save')
        ->assertHasFormErrors(['automatic_matching_threshold' => $rule]);

    expect(resolve(CollectionMatchingSettings::class)->automatic_matching_threshold)->toBe($original);
})->with([
    'missing' => [null, 'required'],
    'zero' => [0, 'gt'],
    'negative' => [-1, 'gt'],
    'above 100' => [101, 'max'],
    'not numeric' => ['invalid', 'numeric'],
]);
