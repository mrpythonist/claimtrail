<?php

namespace App\Observers;

use App\ClaimStatus;
use App\DocumentExtractionStatus;
use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\DocumentExtraction;
use App\Models\WorkflowRun;
use App\Services\WebhookDispatcher;
use App\WorkflowRunStatus;

/**
 * Webhook event observer (v2.0.2).
 *
 * Fires webhook events from the model lifecycle: a claim is
 * created, updated, or paid; a payment is recorded; a
 * document extraction finishes; a workflow run completes.
 * Dispatch is best effort and never throws: a webhook
 * failure must never break the claim, payment, document,
 * or workflow operation that fired it.
 *
 * claim.updated fires on any claim change, including the
 * change that also fires claim.paid: a subscriber that
 * wants both events gets both, in created order.
 */
class WebhookEventObserver
{
    public function __construct(protected WebhookDispatcher $dispatcher) {}

    public function created(object $model): void
    {
        if ($model instanceof Claim) {
            $this->dispatch($model, WebhookDispatcher::EVENT_CLAIM_CREATED, $this->claimData($model));

            return;
        }

        if (! $model instanceof ClaimPayment) {
            return;
        }

        $this->dispatch($model, WebhookDispatcher::EVENT_PAYMENT_RECORDED, [
            'claim_payment_id' => $model->getKey(),
            'claim_id' => $model->claim_id,
            'claim_reference' => $model->claim?->claim_reference,
            'amount' => $model->amount !== null ? (string) $model->amount : null,
            'currency' => $model->currency,
            'payment_method' => $model->payment_method?->value,
            'payment_reference' => $model->payment_reference,
        ]);
    }

    public function updated(object $model): void
    {
        if ($model instanceof Claim) {
            $this->dispatch($model, WebhookDispatcher::EVENT_CLAIM_UPDATED, $this->claimData($model));

            if ($model->wasChanged('status') && $model->status === ClaimStatus::Paid) {
                $this->dispatch($model, WebhookDispatcher::EVENT_CLAIM_PAID, $this->claimData($model));
            }

            return;
        }

        if ($model instanceof DocumentExtraction) {
            if (! $model->wasChanged('status')) {
                return;
            }

            if (! in_array($model->status, [DocumentExtractionStatus::Completed, DocumentExtractionStatus::Verified], true)) {
                return;
            }

            $this->dispatch($model, WebhookDispatcher::EVENT_DOCUMENT_PROCESSED, [
                'document_extraction_id' => $model->getKey(),
                'classification' => $model->classification?->value,
                'status' => $model->status?->value,
                'shipment_id' => $model->matched_shipment_id,
                'is_duplicate' => (bool) $model->is_duplicate,
            ]);

            return;
        }

        if (! $model instanceof WorkflowRun) {
            return;
        }

        if (! $model->wasChanged('status')) {
            return;
        }

        if (! in_array($model->status, [WorkflowRunStatus::Completed, WorkflowRunStatus::Failed], true)) {
            return;
        }

        $this->dispatch($model, WebhookDispatcher::EVENT_WORKFLOW_RUN_COMPLETED, [
            'workflow_run_id' => $model->getKey(),
            'workflow_id' => $model->workflow_id,
            'claim_id' => $model->claim_id,
            'status' => $model->status?->value,
        ]);
    }

    /**
     * The claim payload shared by the claim events: ids,
     * references, statuses, and amounts only.
     *
     * @return array<string, mixed>
     */
    protected function claimData(Claim $claim): array
    {
        return [
            'claim_id' => $claim->getKey(),
            'claim_reference' => $claim->claim_reference,
            'shipment_id' => $claim->shipment_id,
            'status' => $claim->status?->value,
            'claim_category' => $claim->claim_category?->value,
            'requested_amount' => $claim->requested_amount !== null ? (string) $claim->requested_amount : null,
            'accepted_amount' => $claim->accepted_amount !== null ? (string) $claim->accepted_amount : null,
            'recovered_amount' => $claim->recovered_amount !== null ? (string) $claim->recovered_amount : null,
            'currency' => $claim->currency,
        ];
    }

    /**
     * Dispatch one event. Best effort: a dispatcher failure
     * (a misconfigured endpoint, a queue outage) must never
     * break the operation that fired the event.
     *
     * @param  array<string, mixed>  $data
     */
    protected function dispatch(Claim|ClaimPayment|DocumentExtraction|WorkflowRun $model, string $event, array $data): void
    {
        try {
            $organization = $model->organization;

            if ($organization === null) {
                return;
            }

            $this->dispatcher->dispatch($organization, $event, $data);
        } catch (\Throwable) {
            // Webhook dispatch must never break the operation.
        }
    }
}
