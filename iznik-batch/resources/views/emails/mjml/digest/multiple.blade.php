<mjml>
    @include('emails.mjml.partials.head', [
        'preview' => $messageCount . ' new posts on ' . $group->nameshort,
        'styles' => '
            .message-card { border-bottom: 1px solid #eeeeee; padding-bottom: 15px; margin-bottom: 15px; }
            .message-title { font-weight: bold; color: #333333; }
            .message-type { font-size: 12px; color: #338808; text-transform: uppercase; }
        ',
    ])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    Here {{ $messageCount === 1 ? 'is' : 'are' }} <strong>{{ $messageCount }}</strong> new post{{ $messageCount === 1 ? '' : 's' }} on <strong>{{ $group->nameshort }}</strong>:
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach($messages as $message)
        <mj-section background-color="#ffffff" padding="10px 20px" css-class="message-card">
            <mj-column width="25%">
                @if($message['imageUrl'])
                <mj-image
                    width="80px"
                    src="{{ $message['imageUrl'] }}"
                    alt="Photo"
                />
                @else
                <mj-image
                    width="80px"
                    src="{{ $userSite }}/icon.png"
                    alt="No photo"
                />
                @endif
            </mj-column>
            <mj-column width="75%">
                <mj-text css-class="message-type">
                    {{ $message['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}
                </mj-text>
                <mj-text css-class="message-title">
                    <a href="{{ $message['messageUrl'] }}">{{ $message['subject'] }}</a>
                </mj-text>
                @if($message['textbody'])
                <mj-text font-size="13px" color="#666666">
                    {{ \Illuminate\Support\Str::limit($message['textbody'], 150) }}
                </mj-text>
                @endif
                <mj-button href="{{ $message['messageUrl'] }}" align="left" padding="10px 0" mj-class="btn-success" border-radius="3px">
                    View Post
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $userSite }}/browse/{{ $group->id }}" mj-class="btn-secondary" border-radius="3px">
                    See All Posts on {{ $group->nameshort }}
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    You're receiving this because you're a member of {{ $group->nameshort }}.
                    @if($frequency > 0)
                    These emails are sent {{ $frequency === 1 ? 'hourly' : 'every ' . $frequency . ' hours' }}.
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>

        @if(!empty($trackingPixelMjml))
        <mj-section padding="0">
            <mj-column>
                {!! $trackingPixelMjml !!}
            </mj-column>
        </mj-section>
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
