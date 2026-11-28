<?php

namespace App\Services;

/**
 * Reads the structured errors log for the operations endpoints.
 *
 * The errors channel writes one JSON object per line. This service
 * tails the file (never loading more than the configured bytes),
 * parses the lines, and filters to the calling organization: an
 * entry is shown only when its context names that organization.
 * Entries without an organization (infrastructure errors outside
 * any request) are not shown to organization admins.
 */
class ErrorLogService
{
    /**
     * Maximum bytes read from the end of the log file.
     */
    public const MAX_READ_BYTES = 524288;

    /**
     * Recent errors for the organization, newest first.
     *
     * @return array{entries: array<int, array<string, mixed>>, scanned_lines: int}
     */
    public function recentForOrganization(string $organizationId, int $limit = 50): array
    {
        $path = storage_path('logs/errors.json.log');

        if (! is_file($path) || ! is_readable($path)) {
            return ['entries' => [], 'scanned_lines' => 0];
        }

        $lines = $this->tailLines($path, self::MAX_READ_BYTES);
        $entries = [];
        $scanned = 0;

        // Newest first: the file is chronological, so read it back.
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $scanned++;

            $entry = json_decode($line, true);

            if (! is_array($entry)) {
                continue;
            }

            if (($entry['context']['organization_id'] ?? null) !== $organizationId) {
                continue;
            }

            $entries[] = $this->summarize($entry);

            if (count($entries) >= $limit) {
                break;
            }
        }

        return ['entries' => $entries, 'scanned_lines' => $scanned];
    }

    /**
     * Server errors for the organization inside a window.
     *
     * The errors channel only records 5xx failures, so this is a
     * count of recorded errors, not a rate on its own: callers
     * that need a rate divide by their own denominator (the
     * alerting service uses the hourly health probes in the
     * same window). Returns the error count and how many log
     * lines were scanned.
     *
     * @return array{errors: int, scanned_lines: int}
     */
    public function errorCountForOrganization(string $organizationId, int $windowMinutes): array
    {
        $path = storage_path('logs/errors.json.log');

        if (! is_file($path) || ! is_readable($path)) {
            return ['errors' => 0, 'scanned_lines' => 0];
        }

        $since = now()->subMinutes(max(1, $windowMinutes));
        $errors = 0;
        $scanned = 0;

        foreach ($this->tailLines($path, self::MAX_READ_BYTES) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $scanned++;

            $entry = json_decode($line, true);

            if (! is_array($entry)) {
                continue;
            }

            if (($entry['context']['organization_id'] ?? null) !== $organizationId) {
                continue;
            }

            $datetime = isset($entry['datetime']) ? strtotime((string) $entry['datetime']) : false;

            if ($datetime === false || $datetime < $since->getTimestamp()) {
                continue;
            }

            $errors++;
        }

        return ['errors' => $errors, 'scanned_lines' => $scanned];
    }

    /**
     * The fields the API exposes for one error entry.
     *
     * @return array{datetime: string|null, level: string|null, message: string|null, exception: string|null, route: string|null, method: string|null, correlation_id: string|null}
     */
    protected function summarize(array $entry): array
    {
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];

        return [
            'datetime' => isset($entry['datetime']) ? (string) $entry['datetime'] : null,
            'level' => isset($entry['level_name']) ? (string) $entry['level_name'] : null,
            'message' => isset($entry['message']) ? (string) $entry['message'] : null,
            'exception' => isset($context['exception']) ? (string) $context['exception'] : null,
            'route' => isset($context['route']) ? (string) $context['route'] : null,
            'method' => isset($context['method']) ? (string) $context['method'] : null,
            'correlation_id' => isset($context['correlation_id']) ? (string) $context['correlation_id'] : null,
        ];
    }

    /**
     * Last N bytes of a file, split into lines.
     *
     * @return array<int, string>
     */
    protected function tailLines(string $path, int $maxBytes): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $size = filesize($path);

            if ($size === false || $size === 0) {
                return [];
            }

            $offset = $size > $maxBytes ? $size - $maxBytes : 0;
            fseek($handle, $offset);
            $chunk = stream_get_contents($handle);

            if ($chunk === false || $chunk === '') {
                return [];
            }

            // If the read started mid-line, drop the partial first
            // line: it is not valid JSON anyway.
            if ($offset > 0) {
                $firstBreak = strpos($chunk, "\n");
                $chunk = $firstBreak === false ? '' : substr($chunk, $firstBreak + 1);
            }

            return explode("\n", $chunk);
        } finally {
            fclose($handle);
        }
    }
}
