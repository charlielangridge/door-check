<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Models\Site;
use App\Models\User;
use App\Services\StaffVenueAssignments;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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
            ->inverseRelationship('staffSites')
            ->headerActions([
                CreateAction::make()->label('Create staff account')->modal()->databaseTransaction()
                    ->after(fn (User $record) => $record->assignRole('staff')),
                AttachAction::make()->label('Assign existing staff')->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelect(fn (Select $select): Select => $select->label('Staff account')->helperText('Staff may be assigned to several venues. Other venue assignments will be preserved.'))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'staff')->where('guard_name', 'web')))
                    ->using(function (?User $record): void {
                        if ($record === null || ! app(StaffVenueAssignments::class)->assign($record, $this->staffVenue())) {
                            throw ValidationException::withMessages(['recordId' => 'This staff account is no longer eligible or is already assigned. Refresh and try again.']);
                        }
                    })
                    ->failureNotificationTitle('This staff account has changed. Refresh and try again.'),
            ])
            ->pushRecordActions([
                DetachAction::make()->label('Unassign')->modalDescription('Remove access to this venue only. Any other venue assignments will be preserved.')
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
