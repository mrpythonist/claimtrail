<x-mail::message>
# {{ $title }}

{{ $body }}

@if($actionUrl)
<x-mail::button :url="$actionUrl">
Open in ClaimTrail
</x-mail::button>
@endif

You are receiving this because you are a member of **{{ $organizationName }}** on ClaimTrail. You can change which emails you receive in your profile settings.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
