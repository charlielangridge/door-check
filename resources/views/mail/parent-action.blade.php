<x-mail::message>
# {{ $heading }}

{{ $messageText }}

<x-mail::button :url="$actionUrl">{{ $actionLabel }}</x-mail::button>

@if ($permanentReturnUrl)
[Bookmark your permanent adult-page return link]({{ $permanentReturnUrl }})
@endif

If you were not expecting this email, contact the nursery.
</x-mail::message>
