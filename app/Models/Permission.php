<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use Spatie\Permission\Models\Permission as BasePermission;
use Spatie\Permission\PermissionRegistrar;

class Permission extends BasePermission implements \OwenIt\Auditing\Contracts\Auditable
{
    use Auditable;
    use HasFactory;

    /** @return BelongsToMany<Role, $this, AuditedAccessAssignment> */
    public function roles(): BelongsToMany
    {
        $registrar = resolve(PermissionRegistrar::class);

        return $this->belongsToMany(Role::class, config('permission.table_names.role_has_permissions'), $registrar->pivotPermission, $registrar->pivotRole)
            ->using(AuditedAccessAssignment::class);
    }
}
