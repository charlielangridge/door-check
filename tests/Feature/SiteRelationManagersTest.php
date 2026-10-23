<?php

use App\Filament\Resources\Adults\Pages\EditAdult;
use App\Filament\Resources\Adults\RelationManagers\SitesRelationManager as AdultSitesRelationManager;
use App\Filament\Resources\Children\Pages\EditChild;
use App\Filament\Resources\Children\RelationManagers\SitesRelationManager as ChildSitesRelationManager;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\RelationManagers\AdultsRelationManager;
use App\Filament\Resources\Sites\RelationManagers\ChildrenRelationManager;
use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());
});

dataset('site relation managers', [
    'site adults' => [Site::class, Adult::class, AdultsRelationManager::class, EditSite::class, 'adults'],
    'site children' => [Site::class, Child::class, ChildrenRelationManager::class, EditSite::class, 'children'],
    'adult sites' => [Adult::class, Site::class, AdultSitesRelationManager::class, EditAdult::class, 'sites'],
    'child sites' => [Child::class, Site::class, ChildSitesRelationManager::class, EditChild::class, 'sites'],
]);

test('the edit page renders its site relation manager', function (string $ownerModel, string $relatedModel, string $manager, string $page): void {
    $owner = $ownerModel::factory()->create();
    $resource = $page::getResource();
    $relationKey = array_search($manager, $resource::getRelations(), true);

    Livewire::withQueryParams(['relation' => (string) $relationKey])
        ->test($page, ['record' => $owner->getRouteKey()])
        ->assertOk()
        ->assertSeeLivewire($manager);
})->with('site relation managers');

test('records can be attached through the site relation manager', function (string $ownerModel, string $relatedModel, string $manager, string $page, string $relationship): void {
    $owner = $ownerModel::factory()->create();
    $related = $relatedModel::factory()->create();

    Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $related->getKey()])
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertCanSeeTableRecords([$related]);

    expect($owner->{$relationship}()->sole()->is($related))->toBeTrue();
})->with('site relation managers');

test('detaching removes only the selected site link and preserves both records', function (string $ownerModel, string $relatedModel, string $manager, string $page, string $relationship): void {
    $owner = $ownerModel::factory()->create();
    $otherOwner = $ownerModel::factory()->create();
    $related = $relatedModel::factory()->create();
    $owner->{$relationship}()->attach($related);
    $otherOwner->{$relationship}()->attach($related);

    Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->assertCanSeeTableRecords([$related])
        ->assertActionExists(TestAction::make('edit')->table($related))
        ->callAction(TestAction::make('detach')->table($related))
        ->assertNotified()
        ->assertCanNotSeeTableRecords([$related]);

    expect($owner->{$relationship}()->exists())->toBeFalse();
    expect($otherOwner->{$relationship}()->sole()->is($related))->toBeTrue();
    $this->assertModelExists($owner);
    $this->assertModelExists($related);
})->with('site relation managers');
