<?php

namespace App\Models;

use App\CollectionRequestMatchPerson;
use App\CollectionRequestStatus;
use App\Observers\CollectionRequestObserver;
use Database\Factories\CollectionRequestFactory;
use Dyrynda\Database\Support\BindsOnUuid;
use Dyrynda\Database\Support\GeneratesUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'uuid',
    'site_id',
    'requested_at',
    'requester_name_text',
    'requested_child_text',
    'requester_latitude',
    'requester_longitude',
    'adult_id',
    'requested_child_id',
])]
#[Table('collection_request')]
#[ObservedBy([CollectionRequestObserver::class])]
class CollectionRequest extends Model implements HasMedia
{
    use BindsOnUuid, GeneratesUuid;
    /** @use HasFactory<CollectionRequestFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $attributes = [
        'status' => 'pending',
        'adult_match_status' => 'pending',
        'child_match_status' => 'pending',
    ];

    protected $casts = [
        'status' => CollectionRequestStatus::class,
        'adult_match_status' => CollectionRequestStatus::class,
        'child_match_status' => CollectionRequestStatus::class,
        'adult_match_confidence' => 'float',
        'child_match_confidence' => 'float',
        'matching_threshold' => 'float',
        'adult_match_candidates' => 'array',
        'child_match_candidates' => 'array',
        'adult_candidates_truncated' => 'boolean',
        'child_candidates_truncated' => 'boolean',
        'adult_site_membership_changed' => 'boolean',
        'child_site_membership_changed' => 'boolean',
        'requested_at' => 'datetime',
        'requester_latitude' => 'decimal:8',
        'requester_longitude' => 'decimal:8',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('requester_photo')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    /** @return BelongsTo<Adult, $this> */
    public function adult(): BelongsTo
    {
        return $this->belongsTo(Adult::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Child, $this> */
    public function requestedChild(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'requested_child_id');
    }

    /** @return list<string> */
    public function approvalReasons(): array
    {
        if ($this->status !== CollectionRequestStatus::NeedsApproval) {
            return [];
        }

        $reasons = [];

        foreach (CollectionRequestMatchPerson::cases() as $person) {
            if ($this->getAttribute($person->idColumn()) !== null) {
                continue;
            }

            $label = ucfirst($person->value);
            if ($this->getAttribute($person->value.'_site_membership_changed')) {
                $reasons[] = "{$label}: site membership changed while matching was in progress; staff must review the identification.";

                continue;
            }
            $candidates = $this->getAttribute($person->value.'_match_candidates') ?? [];
            $confidence = $this->getAttribute($person->value.'_match_confidence');

            if ($candidates === []) {
                $reasons[] = "{$label}: no match was found; a person must be selected manually.";
            } elseif ($this->matching_threshold === null) {
                $reasons[] = "{$label}: no automatic matching threshold was recorded; review the candidate checks manually.";
            } else {
                $qualified = array_filter($candidates, fn (array $candidate): bool => $candidate['confidence'] >= $this->matching_threshold);

                if (count($qualified) > 1) {
                    $reasons[] = "{$label}: multiple candidates meet the {$this->matching_threshold}% threshold; a person must be selected manually.";
                } elseif ($qualified === []) {
                    $reasons[] = $confidence !== null
                        ? "{$label}: the highest JEV confidence is {$confidence}%, below the {$this->matching_threshold}% threshold."
                        : "{$label}: no candidate meets the {$this->matching_threshold}% threshold.";
                }
            }

            if ($this->getAttribute($person->value.'_candidates_truncated')) {
                $reasons[] = "{$label}: the candidate search was truncated, so automatic matching could not safely select a person.";
            }
        }

        if ($this->adult_id !== null && $this->requested_child_id !== null && ! $this->adult?->children()->whereKey($this->requested_child_id)->exists()) {
            $reasons[] = 'The matched child is not linked to the matched adult. Their relationship must be confirmed before collection can be approved.';
        }

        return $reasons ?: ['This request is still marked as needing approval. Review the saved matching results and the current adult–child relationship.'];
    }
}
