<?php

namespace App\Filament\Resources\CollectionRequests\Schemas;

use App\CollectionRequestStatus;
use App\Models\CollectionRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CollectionRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('approval_reasons')
                            ->label('Why approval is needed')
                            ->state(fn (CollectionRequest $record): array => $record->approvalReasons())
                            ->listWithLineBreaks()
                            ->color('warning')
                            ->columnSpanFull()
                            ->visible(fn (CollectionRequest $record): bool => $record->status === CollectionRequestStatus::NeedsApproval),
                        TextEntry::make('requested_at')->dateTime(),
                        TextEntry::make('requester_name_text')->label('Submitted requester name'),
                        TextEntry::make('requested_child_text')->label('Submitted child name'),
                        TextEntry::make('requester_latitude')->placeholder('Not provided'),
                        TextEntry::make('requester_longitude')->placeholder('Not provided'),
                        TextEntry::make('matching_threshold')->label('Automatic matching threshold')->suffix('%')->placeholder('Not yet set'),
                        TextEntry::make('current_relationship')
                            ->label('Current adult–child relationship')
                            ->state(fn (CollectionRequest $record): ?string => $record->adult_id !== null && $record->requested_child_id !== null
                                ? $record->adult?->children()->whereKey($record->requested_child_id)->first()?->pivot?->getAttribute('relationship')
                                : null)
                            ->placeholder('No linked relationship'),
                    ]),
                self::matchingSection('adult', 'Adult matching / JEV checks', 'adult'),
                self::matchingSection('child', 'Child matching / JEV checks', 'requestedChild'),
                Section::make('Record details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('id')->label('Request ID'),
                        TextEntry::make('uuid')->label('UUID')->copyable(),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                    ]),
            ]);
    }

    private static function matchingSection(string $prefix, string $heading, string $relationship): Section
    {
        return Section::make($heading)
            ->description('Saved matching results. Name similarity is used to find candidates; JEV confidence is used against the request threshold.')
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                TextEntry::make($prefix.'_match_status')->label('Matching status')->badge(),
                TextEntry::make($prefix.'_match_confidence')->label('Highest JEV confidence')->numeric(decimalPlaces: 2)->suffix('%')->placeholder('Not available'),
                TextEntry::make($relationship.'.name')->label('Matched name')->placeholder('Not matched'),
                TextEntry::make($relationship.'.id')->label('Matched record ID')->placeholder('Not matched'),
                TextEntry::make($relationship.'.date_of_birth')->label('Date of birth')->date()->placeholder('Not available'),
                TextEntry::make($prefix.'_candidates_truncated')
                    ->label('Candidate search')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Truncated — manual review required' : 'Not truncated'),
                RepeatableEntry::make($prefix.'_match_candidates')
                    ->label('Candidate checks')
                    ->placeholder('No candidate checks recorded')
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('Record ID'),
                        TableColumn::make('Name'),
                        TableColumn::make('Name similarity (0–1)'),
                        TableColumn::make('JEV confidence'),
                    ])
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('name'),
                        TextEntry::make('similarity')->numeric(decimalPlaces: 4),
                        TextEntry::make('confidence')->numeric(decimalPlaces: 2)->suffix('%'),
                    ]),
            ]);
    }
}
