<?php

namespace Tests\Feature;

use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function errorsLogPath(): string
    {
        return storage_path('logs/errors.json.log');
    }

    protected function clearErrorsLog(): void
    {
        if (is_file($this->errorsLogPath())) {
            unlink($this->errorsLogPath());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearErrorsLog();
    }

    protected function tearDown(): void
    {
        $this->clearErrorsLog();
        parent::tearDown();
    }

    /**
     * Seed the errors log with crafted JSON lines.
     */
    protected function seedErrorsLog(array $entries): void
    {
        $lines = array_map(
            fn (array|string $e) => is_array($e) ? json_encode($e) : $e,
            $entries
        );
        file_put_contents($this->errorsLogPath(), implode("\n", $lines)."\n");
    }

    protected function errorEntry(string $organizationId, string $message): array
    {
        return [
            'message' => $message,
            'context' => [
                'exception' => 'RuntimeException',
                'organization_id' => $organizationId,
                'route' => 'api/v1/claims',
                'method' => 'POST',
                'correlation_id' => 'corr-1',
            ],
            'level' => 400,
            'level_name' => 'ERROR',
            'datetime' => '2026-11-18T10:00:00+00:00',
            'extra' => [],
        ];
    }

    public function test_a_server_error_is_recorded_in_the_errors_channel(): void
    {
        Route::get('/api/v1/_test-boom', function () {
            throw new \RuntimeException('boom-test-error');
        });

        $this->getJson('/api/v1/_test-boom')->assertServerError();

        $contents = (string) file_get_contents($this->errorsLogPath());
        $this->assertStringContainsString('boom-test-error', $contents);
        $this->assertStringContainsString('RuntimeException', $contents);

        $entry = json_decode(trim($contents), true);
        $this->assertSame('api/v1/_test-boom', $entry['context']['route']);
        $this->assertSame('GET', $entry['context']['method']);
    }

    public function test_a_404_is_not_recorded_as_an_error(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound();

        $this->assertFileDoesNotExist($this->errorsLogPath());
    }

    public function test_ops_errors_endpoint_shows_only_the_organizations_entries(): void
    {
        [, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization();

        $this->seedErrorsLog([
            $this->errorEntry((string) $orgA->getKey(), 'org A error'),
            $this->errorEntry((string) $orgB->getKey(), 'org B error'),
            // No organization: an infrastructure error outside any
            // request. Organization admins do not see it.
            array_merge($this->errorEntry('', 'infra error'), [
                'context' => [
                    'exception' => 'RuntimeException',
                    'route' => 'console',
                    'method' => 'CLI',
                ],
            ]),
            'not json at all',
        ]);

        $response = $this->withHeaders($this->orgHeaders($orgA))
            ->getJson('/api/v1/ops/errors')
            ->assertOk();

        $messages = collect($response->json('data.entries'))->pluck('message')->all();
        $this->assertSame(['org A error'], $messages);
        $this->assertSame(4, $response->json('data.scanned_lines'));
    }

    public function test_ops_errors_endpoint_returns_empty_when_no_log_exists(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/errors')
            ->assertOk()
            ->assertJsonPath('data.entries', [])
            ->assertJsonPath('data.scanned_lines', 0);
    }

    public function test_ops_errors_endpoint_requires_the_operations_permission(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::BillingStaff);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/errors')
            ->assertForbidden();
    }
}
