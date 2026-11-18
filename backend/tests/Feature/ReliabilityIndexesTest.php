<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReliabilityIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Index names present on a table, from the SQLite schema.
     *
     * @return array<int, string>
     */
    protected function indexNames(string $table): array
    {
        return collect(DB::select(
            "select name from sqlite_master where type = 'index' and tbl_name = ?",
            [$table]
        ))->pluck('name')->all();
    }

    public function test_jobs_table_has_the_queue_polling_index(): void
    {
        $this->assertContains(
            'jobs_queue_available_at_reserved_at_index',
            $this->indexNames('jobs')
        );
    }

    public function test_shipments_table_has_the_delivery_appointment_index(): void
    {
        $this->assertContains(
            'shipments_organization_id_delivery_appointment_index',
            $this->indexNames('shipments')
        );
    }

    public function test_claims_table_has_the_requested_amount_index(): void
    {
        $this->assertContains(
            'claims_organization_id_requested_amount_index',
            $this->indexNames('claims')
        );
    }

    public function test_job_executions_key_is_unique(): void
    {
        $this->assertContains(
            'job_executions_key_unique',
            $this->indexNames('job_executions')
        );
    }
}
