<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Claim Package {{ $claim->claim_reference }}</title>
    <style>
        /* Plain print CSS: dompdf supports a small subset of CSS, so
           the layout uses tables and simple blocks only. */
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #0f172a; }
        h2 { font-size: 13px; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #cbd5e1; color: #0f172a; }
        .meta { color: #64748b; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { text-align: left; padding: 5px 6px; vertical-align: top; }
        th { width: 180px; color: #64748b; font-weight: normal; }
        .amounts td { font-weight: bold; }
        .evidence th { background: #f1f5f9; color: #0f172a; font-weight: bold; }
        .evidence td, .evidence th { border-bottom: 1px solid #e2e8f0; }
        .note { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 10px; margin: 8px 0; }
        .timeline td:first-child { width: 150px; color: #64748b; }
        .footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #cbd5e1; font-size: 10px; color: #64748b; }
    </style>
</head>
<body>
    <h1>Claim Package</h1>
    <p class="meta">
        Claim {{ $claim->claim_reference }} &middot;
        {{ $claim->claim_category->label() }} &middot;
        {{ $claim->status->label() }} &middot;
        Generated {{ $generatedAt->format('F j, Y g:i A') }}
    </p>

    <h2>Organization</h2>
    <table>
        <tr><th>Organization</th><td>{{ $organization->name }}</td></tr>
        @if(!empty($organization->settings['address'] ?? null))
            <tr><th>Address</th><td>{{ $organization->settings['address'] }}</td></tr>
        @endif
    </table>

    <h2>Shipment</h2>
    <table>
        <tr><th>Internal reference</th><td>{{ $shipment->internal_reference }}</td></tr>
        @if($shipment->external_load_reference)
            <tr><th>External load reference</th><td>{{ $shipment->external_load_reference }}</td></tr>
        @endif
        <tr><th>Broker / customer</th><td>{{ $shipment->broker_or_customer_name }}</td></tr>
        @if($shipment->carrier)
            <tr><th>Carrier</th><td>{{ $shipment->carrier->name }}</td></tr>
        @endif
        @if($shipment->driver_name)
            <tr><th>Driver</th><td>{{ $shipment->driver_name }}</td></tr>
        @endif
        @if($shipment->pickup_location)
            <tr><th>Pickup</th><td>{{ $shipment->pickup_location }}@if($shipment->pickup_appointment) &middot; {{ $shipment->pickup_appointment->format('M j, Y g:i A') }}@endif</td></tr>
        @endif
        @if($shipment->delivery_location)
            <tr><th>Delivery</th><td>{{ $shipment->delivery_location }}@if($shipment->delivery_appointment) &middot; {{ $shipment->delivery_appointment->format('M j, Y g:i A') }}@endif</td></tr>
        @endif
        @if($shipment->base_rate !== null)
            <tr><th>Agreed base rate</th><td>{{ $shipment->currency }} {{ number_format((float) $shipment->base_rate, 2) }}</td></tr>
        @endif
    </table>

    @if($event)
        <h2>Event</h2>
        <table>
            <tr><th>Category</th><td>{{ $event->category?->label ?? $event->event_category_id }}</td></tr>
            <tr><th>Date</th><td>{{ $event->event_date->format('F j, Y') }}</td></tr>
            @if($event->start_time || $event->end_time)
                <tr><th>Time</th><td>{{ $event->start_time ?? 'not recorded' }} to {{ $event->end_time ?? 'not recorded' }}@if($event->duration_minutes !== null) ({{ $event->duration_minutes }} minutes)@endif</td></tr>
            @endif
            @if($event->location)
                <tr><th>Location</th><td>{{ $event->location }}</td></tr>
            @endif
            @if($event->description)
                <tr><th>Description</th><td>{{ $event->description }}</td></tr>
            @endif
        </table>
    @endif

    <h2>Claim</h2>
    <table class="amounts">
        <tr><th>Requested amount</th><td>{{ $claim->currency }} {{ number_format((float) $claim->requested_amount, 2) }}</td></tr>
        @if($claim->accepted_amount !== null)
            <tr><th>Accepted amount</th><td>{{ $claim->currency }} {{ number_format((float) $claim->accepted_amount, 2) }}</td></tr>
        @endif
        @if($claim->recovered_amount !== null)
            <tr><th>Recovered amount</th><td>{{ $claim->currency }} {{ number_format((float) $claim->recovered_amount, 2) }}</td></tr>
        @endif
    </table>
    <table>
        @if($claim->description)
            <tr><th>Description</th><td>{{ $claim->description }}</td></tr>
        @endif
        @if($claim->external_broker_reference)
            <tr><th>Broker reference</th><td>{{ $claim->external_broker_reference }}</td></tr>
        @endif
        @if($claim->submission_deadline_at)
            <tr><th>Submission deadline</th><td>{{ $claim->submission_deadline_at->format('F j, Y g:i A') }}</td></tr>
        @endif
        @if($claim->submitted_at)
            <tr><th>Submitted</th><td>{{ $claim->submitted_at->format('F j, Y g:i A') }}</td></tr>
        @endif
    </table>

    @if($includeNotes && $claim->internal_notes)
        <h2>Internal notes</h2>
        <div class="note">{{ $claim->internal_notes }}</div>
    @endif

    <h2>Supporting evidence index</h2>
    @if($evidenceFiles->isEmpty())
        <p>No evidence files are attached to this claim.</p>
    @else
        <table class="evidence">
            <tr><th>File</th><th>Category</th><th>Status</th><th>Uploaded</th></tr>
            @foreach($evidenceFiles as $file)
                <tr>
                    <td>{{ $file->original_filename }}</td>
                    <td>{{ $file->document_category->label() }}</td>
                    <td>{{ $file->verification_status->label() }}</td>
                    <td>{{ $file->created_at->format('M j, Y') }}</td>
                </tr>
            @endforeach
        </table>
        <p>Original documents are held in ClaimTrail and are available on request. This package lists them by reference; it does not embed the files.</p>
    @endif

    <h2>Event timeline</h2>
    @if($timeline->isEmpty())
        <p>No status changes recorded yet.</p>
    @else
        <table class="timeline">
            @foreach($timeline as $entry)
                <tr>
                    <td>{{ $entry->created_at->format('M j, Y g:i A') }}</td>
                    <td>
                        {{ $entry->from_status ? ucfirst(str_replace('_', ' ', $entry->from_status)).' to ' : '' }}{{ ucfirst(str_replace('_', ' ', $entry->to_status)) }}@if($entry->reason) &mdash; {{ $entry->reason }}@endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="footer">
        This document is a supporting evidence package prepared by {{ $organization->name }}.
        It is not a guarantee that the claim will be accepted or paid. Contractual
        eligibility and submission requirements are set by the broker and the
        applicable contract. Generated {{ $generatedAt->format('F j, Y g:i A') }}.
    </p>
</body>
</html>
