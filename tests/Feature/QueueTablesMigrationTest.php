<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueTablesMigrationTest extends TestCase
{
    public function test_jobs_table_exists_after_migrations(): void
    {
        $this->assertTrue(
            Schema::hasTable('jobs'),
            'CONTRACT NOT YET LANDED: the "jobs" table is missing — the Phase 1.5 migration creating queue tables (jobs, job_batches, failed_jobs) has not been run or does not exist.'
        );
    }

    public function test_job_batches_table_exists_after_migrations(): void
    {
        $this->assertTrue(
            Schema::hasTable('job_batches'),
            'CONTRACT NOT YET LANDED: the "job_batches" table is missing — the Phase 1.5 migration creating queue tables (jobs, job_batches, failed_jobs) has not been run or does not exist.'
        );
    }

    public function test_failed_jobs_table_exists_after_migrations(): void
    {
        $this->assertTrue(
            Schema::hasTable('failed_jobs'),
            'CONTRACT NOT YET LANDED: the "failed_jobs" table is missing — the Phase 1.5 migration creating queue tables (jobs, job_batches, failed_jobs) has not been run or does not exist.'
        );
    }
}
