<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Holds the correlation id for the current request or job run.
 *
 * The CorrelationId middleware sets it from the X-Correlation-Id
 * header (or generates one) for HTTP requests. Console commands
 * and queued jobs set one per run so their audit rows can be
 * traced back to the run that wrote them. AuditService reads it
 * when recording a row.
 */
class Correlation
{
    protected ?string $id = null;

    public function set(?string $id): void
    {
        $this->id = $id;
    }

    public function get(): ?string
    {
        return $this->id;
    }

    /**
     * Generate a fresh id and remember it.
     */
    public function fresh(): string
    {
        $this->id = (string) Str::uuid();

        return $this->id;
    }
}
