<?php

namespace Tests\Feature;

use App\Logging\ContextProcessor;
use App\Support\Correlation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class StructuredLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['laravel.json.log', 'errors.json.log'] as $file) {
            $path = storage_path('logs/'.$file);

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (['laravel.json.log', 'errors.json.log'] as $file) {
            $path = storage_path('logs/'.$file);

            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_json_channel_writes_one_valid_json_object_per_line(): void
    {
        app(Correlation::class)->set('corr-test-123');

        Log::channel('json')->info('structured hello', ['order_id' => 42]);

        $lines = array_values(array_filter(
            explode("\n", (string) file_get_contents(storage_path('logs/laravel.json.log')))
        ));

        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true);

        $this->assertSame('structured hello', $entry['message']);
        $this->assertSame(42, $entry['context']['order_id']);
        $this->assertSame('corr-test-123', $entry['extra']['correlation_id']);
        $this->assertArrayHasKey('app', $entry['extra']);
        $this->assertSame('testing', $entry['extra']['environment']);
    }

    public function test_errors_channel_only_records_error_level_and_above(): void
    {
        Log::channel('errors')->info('not an error');
        Log::channel('errors')->error('a real error');

        $contents = (string) file_get_contents(storage_path('logs/errors.json.log'));
        $lines = array_values(array_filter(explode("\n", $contents)));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('a real error', $lines[0]);
        $this->assertStringNotContainsString('not an error', $contents);
    }

    public function test_context_processor_adds_correlation_app_and_environment(): void
    {
        app(Correlation::class)->set('corr-unit-456');

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new ContextProcessor);
        $logger->info('hello');

        $record = $handler->getRecords()[0];

        $this->assertSame('corr-unit-456', $record['extra']['correlation_id']);
        $this->assertSame(config('app.name'), $record['extra']['app']);
        $this->assertSame('testing', $record['extra']['environment']);
    }

    public function test_context_processor_tolerates_a_missing_correlation_id(): void
    {
        app(Correlation::class)->set(null);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new ContextProcessor);
        $logger->info('hello');

        $record = $handler->getRecords()[0];

        $this->assertNull($record['extra']['correlation_id']);
    }
}
