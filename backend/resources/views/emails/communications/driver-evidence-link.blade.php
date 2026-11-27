<x-mail::message>
# Submit evidence for {{ $organizationName }}

{{ $organizationName }} asked you to submit evidence for a shipment{{ $shipmentReference ? ' ('.$shipmentReference.')' : '' }}{{ $claimReference ? ' on claim '.$claimReference : '' }}. You do not need a ClaimTrail account: the link below opens a page where you can upload the file.

<x-mail::button :url="$submitUrl">
Submit evidence
</x-mail::button>

This link expires on {{ $expiresAt->toDayDateTimeString() }} and can be used {{ $maxUses === 1 ? 'once' : $maxUses.' times' }}. After that, or if it is revoked, it stops working. The link only covers this shipment{{ $claimReference ? ' and claim' : '' }} — nothing else.

If you were not expecting this email, you can ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
