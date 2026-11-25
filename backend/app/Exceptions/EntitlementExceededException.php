<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Thrown by EntitlementService when an organization hits a plan
 * limit (v1.2.1, v0.5.0 section 9.2).
 *
 * Renders as HTTP 402 with an upgrade prompt that names the limit
 * hit and the tier that removes it, so the frontend can show the
 * prompt at the point of the refused action instead of a generic
 * error. The message is the human-readable form; the `limit`
 * block carries the numbers for clients that render their own
 * prompt.
 */
class EntitlementExceededException extends Exception
{
    public function __construct(
        public readonly string $resource,
        public readonly ?int $limit,
        public readonly int $used,
        public readonly string $plan,
        public readonly ?string $upgradePlan,
        public readonly ?string $upgradePlanName,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'plan_limit_exceeded',
            'limit' => [
                'resource' => $this->resource,
                'limit' => $this->limit,
                'used' => $this->used,
                'plan' => $this->plan,
                'upgrade_plan' => $this->upgradePlan,
                'upgrade_plan_name' => $this->upgradePlanName,
            ],
        ], 402);
    }
}
