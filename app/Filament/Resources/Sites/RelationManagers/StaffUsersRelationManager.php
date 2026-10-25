<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Models\Site;
use App\Models\User;
use App\Services\StaffVenueAssignments;
use Filament\Actions\AssociateAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DissociateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'staffUsers';

    protected static ?string $relatedResource = UserResource::class;

    protected static ?string $title = 'Staff';

    public function form(Schema $schema): Schema
    {
        return $schema->components(UserResource::accountFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->inverseRelationship('staffSite')
            ->headerActions([
                CreateAction::make()->label('Create staff account')->modal()->databaseTransaction()
                    ->after(fn (User $record) => $record->assignRole('staff')),
                AssociateAction::make()->label('Assign existing staff')->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelect(fn (Select $select): Select => $select->label('Staff account')->helperText('Choose an unassigned staff account. To move staff from another venue, edit their account under Staff & users.'))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->whereNull('staff_site_id')->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'staff')->where('guard_name', 'web')))
                    ->using(fn (User $record): bool => app(StaffVenueAssignments::class)->assign($record, $this->staffVenue()))
                    ->failureNotificationTitle('This staff account has changed. Refresh and try again.'),
            ])
            ->pushRecordActions([
                DissociateAction::make()->label('Unassign')->modalDescription('This account will no longer be able to open a staff board until a venue is assigned again.')
                    ->using(fn (User $record): bool => app(StaffVenueAssignments::class)->unassign($record, $this->staffVenue()))
                    ->failureNotificationTitle('This staff account has changed. Refresh and try again.'),
            ]);
    }

    private function staffVenue(): Site
    {
        $site = $this->getOwnerRecord();
        if (! $site instanceof Site) {
            abort(404);
        }

        return $site;
    }
}
