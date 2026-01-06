{{ $message->type === 'Offer' ? 'OFFER' : 'WANTED' }} on {!! $group->nameshort !!}

{!! $message->subject !!}

@if($messageText)
{!! \Illuminate\Support\Str::limit($messageText, 500) !!}

@endif
@if($message->type === 'Offer')
If you're interested, click here: {!! $messageUrl !!}
@else
If you can help, click here: {!! $messageUrl !!}
@endif

---
You're receiving this because you're a member of {!! $group->nameshort !!}.
To change your notification settings: {!! $settingsUrl !!}
