<?php

use App\Filament\Resources\Adults\Pages\EditAdult;
use App\Filament\Resources\Adults\RelationManagers\ChildrenRelationManager;
use App\Filament\Resources\Children\Pages\EditChild;
use App\Filament\Resources\Children\RelationManagers\AdultsRelationManager;
use App\Models\Adult;
use App\Models\Child;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());
});

dataset('adult child relation managers', [
    'children of an adult' => [Adult::class, Child::class, ChildrenRelationManager::class, EditAdult::class, 'children'],
    'adults of a child' => [Child::class, Adult::class, AdultsRelationManager::class, EditChild::class, 'adults'],
]);

test('the edit page renders the relation manager', function (string $ownerModel, string $relatedModel, string $manager, string $page): void {
    $owner = $ownerModel::factory()->create();

    Livewire::test($page, ['record' => $owner->getKey()])
        ->assertOk()
        ->assertSeeLivewire($manager);
})->with('adult child relation managers');

test('the table displays the relationship and detaches only the selected link', function (string $ownerModel, string $relatedModel, string $manager, string $page, string $relationship): void {
    $owner = $ownerModel::factory()->create();
    $otherOwner = $ownerModel::factory()->create();
    $related = $relatedModel::factory()->create();
    $owner->{$relationship}()->attach($related, ['relationship' => 'Mother']);
    $otherOwner->{$relationship}()->attach($related, ['relationship' => 'Guardian']);

    Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->assertOk()
        ->assertCanSeeTableRecords([$related])
        ->assertTableColumnStateSet('relationship', 'Mother', record: $related)
        ->assertActionExists(TestAction::make('edit')->table($related))
        ->callAction(TestAction::make('detach')->table($related))
        ->assertNotified()
        ->assertCanNotSeeTableRecords([$related]);

    expect($owner->{$relationship}()->exists())->toBeFalse();
    expect($otherOwner->{$relationship}()->sole()->pivot->relationship)->toBe('Guardian');
    $this->assertModelExists($owner);
    $this->assertModelExists($related);
})->with('adult child relation managers');

test('attaching saves the relationship pivot value', function (string $ownerModel, string $relatedModel, string $manager, string $page, string $relationship): void {
    $owner = $ownerModel::factory()->create();
    $related = $relatedModel::factory()->create();

    Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $related->getKey(),
            'relationship' => 'Father',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertTableColumnStateSet('relationship', 'Father', record: $related);

    expect($owner->{$relationship}()->sole()->pivot->relationship)->toBe('Father');
})->with('adult child relation managers');

test('attaching requires a relationship value', function (string $ownerModel, string $relatedModel, string $manager, string $page, string $relationship): void {
    $owner = $ownerModel::factory()->create();
    $related = $relatedModel::factory()->create();

    Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $related->getKey(),
            'relationship' => '',
        ])
        ->assertHasFormErrors(['relationship' => 'required']);

    expect($owner->{$relationship}()->exists())->toBeFalse();
})->with('adult child relation managers');
