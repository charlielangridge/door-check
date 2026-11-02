<?php

namespace App\Models;

use Database\Factories\SiteDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'current_version_id', 'is_archived'])]
class SiteDocument extends Model
{
    /** @use HasFactory<SiteDocumentFactory> */
    use HasFactory;

    protected $casts = ['is_archived' => 'boolean'];

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }
}
