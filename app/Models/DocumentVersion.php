<?php

namespace App\Models;

use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_document_id', 'number', 'title', 'markdown', 'pdf_path', 'requires_acceptance', 'published_by'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    /** @return HasMany<DocumentAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(DocumentAcceptance::class);
    }

    protected $casts = ['requires_acceptance' => 'boolean'];

    /** @return BelongsTo<SiteDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SiteDocument::class, 'site_document_id');
    }
}
