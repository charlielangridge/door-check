<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use Spatie\Permission\Models\Role as BaseRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends BaseRole implements \OwenIt\Auditing\Contracts\Auditable
{
    use Auditable;

    /** @return BelongsToMany<Permission, $this, AuditedAccessAssignment> */
    public function permissions(): BelongsToMany
    {
        $registrar = app(PermissionRegistrar::class);

        return $this->belongsToMany(Permission::class, config('permission.table_names.role_has_permissions'), $registrar->pivotRole, $registrar->pivotPermission)
            ->using(AuditedAccessAssignment::class);
    }
}
