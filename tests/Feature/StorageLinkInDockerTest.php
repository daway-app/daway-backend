<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageLinkInDockerTest extends TestCase
{
    public function test_dockerfile_includes_storage_link_command(): void
    {
        $dockerfile = File::get(base_path('Dockerfile'));

        $this->assertStringContainsString('storage:link', $dockerfile);
    }

    public function test_dockerfile_uses_idempotent_storage_link(): void
    {
        // يجب أن يستخدم `|| true` لأن storage:link يفشل إذا الـ symlink موجود مسبقاً.
        $dockerfile = File::get(base_path('Dockerfile'));

        $this->assertStringContainsString('storage:link || true', $dockerfile);
    }

    public function test_dockerfile_runs_storage_link_before_migrate(): void
    {
        $dockerfile = File::get(base_path('Dockerfile'));

        $storagePos = strpos($dockerfile, 'storage:link');
        $migratePos = strpos($dockerfile, 'migrate --force');

        $this->assertNotFalse($storagePos);
        $this->assertNotFalse($migratePos);
        $this->assertLessThan($migratePos, $storagePos, 'storage:link should run before migrate');
    }
}
