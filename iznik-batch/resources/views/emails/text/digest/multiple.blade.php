Hi {!! $user->displayname ?? 'there' !!},

Here {{ $messageCount === 1 ? 'is' : 'are' }} {{ $messageCount }} new post{{ $messageCount === 1 ? '' : 's' }} on {!! $group->nameshort !!}:

@foreach($messages as $message)
---
{{ $message['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}: {!! $message['subject'] !!}
@if($message['textbody'])

{!! \Illuminate\Support\Str::limit($message['textbody'], 150) !!}
@endif

View post: {!! $message['messageUrl'] !!}

@endforeach
---
You're receiving this because you're a member of {!! $group->nameshort !!}.
@if($frequency > 0)
These emails are sent {{ $frequency === 1 ? 'hourly' : 'every ' . $frequency . ' hours' }}.
@endif
To change your notification settings: {!! $settingsUrl !!}
