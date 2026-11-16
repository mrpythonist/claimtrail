<x-mail::message>
# You are invited to {{ $organizationName }}

{{ $inviterName ?? 'A colleague' }} invited you to join **{{ $organizationName }}** on ClaimTrail as **{{ $roleLabel }}**.

ClaimTrail helps trucking companies track additional shipment charges, keep the evidence together, and follow each claim through to payment.

<x-mail::button :url="$acceptUrl">
Accept the invitation
</x-mail::button>

This invitation expires on {{ $expiresAt->toDayDateTimeString() }}. If you do not have a ClaimTrail account yet, create one with this email address first, then open the link again.

If you were not expecting this invitation, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
