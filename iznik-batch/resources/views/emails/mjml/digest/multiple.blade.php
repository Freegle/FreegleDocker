<mjml>
    <mj-head>
        <mj-attributes>
            <mj-all font-family="Arial, sans-serif" />
            <mj-text font-size="14px" color="#333333" line-height="1.5" />
            <mj-button background-color="#5cb85c" color="#ffffff" font-size="14px" />
        </mj-attributes>
        <mj-style inline="inline">
            .message-card { border-bottom: 1px solid #eeeeee; padding-bottom: 15px; margin-bottom: 15px; }
            .message-title { font-weight: bold; color: #333333; }
            .message-type { font-size: 12px; color: #5cb85c; text-transform: uppercase; }
            a { color: #5cb85c; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </mj-style>
        <mj-title>{{ $messageCount }} new posts on {{ $group->nameshort }}</mj-title>
    </mj-head>
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Hi {{ $user->display_name ?? 'there' }},
                </mj-text>
                <mj-text>
                    Here {{ $messageCount === 1 ? 'is' : 'are' }} <strong>{{ $messageCount }}</strong> new post{{ $messageCount === 1 ? '' : 's' }} on <strong>{{ $group->nameshort }}</strong>:
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach($messages as $message)
        <mj-section background-color="#ffffff" padding="10px 20px" css-class="message-card">
            <mj-column width="25%">
                @if($message->attachments && $message->attachments->isNotEmpty())
                <mj-image
                    width="80px"
                    src="{{ $userSite }}/img/{{ $message->attachments->first()->id }}"
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
                    {{ $message->type === 'Offer' ? 'OFFER' : 'WANTED' }}
                </mj-text>
                <mj-text css-class="message-title">
                    <a href="{{ $userSite }}/message/{{ $message->id }}">{{ $message->subject }}</a>
                </mj-text>
                @if($message->textbody)
                <mj-text font-size="13px" color="#666666">
                    {{ \Illuminate\Support\Str::limit($message->textbody, 150) }}
                </mj-text>
                @endif
                <mj-button href="{{ $userSite }}/message/{{ $message->id }}" align="left" padding="10px 0">
                    View Post
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $userSite }}/browse/{{ $group->id }}" background-color="#337ab7">
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

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
