{!! $adminSubject !!}

{!! $adminText !!}
@if($ctaLink && $ctaText)

{{ $ctaText }}: {!! $ctaLink !!}
@endif
@if($groupName)

---
This message was sent to members of {{ $groupName }} on {{ config('freegle.branding.name') }}.
@if($modsEmail)
Contact your group volunteers: {{ $modsEmail }}
@endif
@endif
@if($marketingOptOutUrl)

Don't want to receive these emails? Opt out: {!! $marketingOptOutUrl !!}
@endif

---
Change your email settings: {!! $settingsUrl !!}
