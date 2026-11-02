<?php

namespace App\Models;

use Database\Factories\DocumentAcceptanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['adult_access_id', 'document_version_id', 'actor_name', 'actor_email', 'accepted_at', 'ip_address', 'user_agent'])]
class DocumentAcceptance extends Model
{
    /** @use HasFactory<DocumentAcceptanceFactory> */
    use HasFactory;

    protected $casts = ['accepted_at' => 'datetime'];

    public $timestamps = false;
}
