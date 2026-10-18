<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['admin', 'pharmacy', 'patient', 'rider'];

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
