<?php

namespace App\Models;

use Database\Factories\ChildAdultSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['site_id', 'child_id', 'adult_id', 'is_linked', 'is_manager', 'document_responsible'])]
class ChildAdultSite extends Model
{
    /** @use HasFactory<ChildAdultSiteFactory> */
    use HasFactory;

    protected $casts = ['is_linked' => 'boolean', 'is_manager' => 'boolean', 'document_responsible' => 'boolean'];
}
