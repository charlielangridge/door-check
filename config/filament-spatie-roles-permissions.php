<?php

return [
    'scope_to_tenant' => false,
    'scope_roles_to_tenant' => false,
    'scope_premissions_to_tenant' => false,
    'super_admin_role_name' => 'super-admin',
    'guard_names' => ['web' => 'web'],
    'default_guard_name' => 'web',
    'should_display_relation_managers' => [
        'permissions' => true,
        'roles' => true,
        'users' => false,
    ],
];
