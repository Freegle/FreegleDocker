<mjml>
    <mj-head>
        <mj-attributes>
            <mj-all font-family="Arial, sans-serif" />
            <mj-text font-size="14px" color="#333333" line-height="1.5" />
            <mj-button background-color="#5cb85c" color="#ffffff" font-size="14px" />
        </mj-attributes>
        <mj-style inline="inline">
            .message-type { font-size: 12px; color: #5cb85c; text-transform: uppercase; font-weight: bold; }
            a { color: #5cb85c; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </mj-style>
        <mj-title>{{ $message->subject }}</mj-title>
    </mj-head>
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text css-class="message-type">
                    {{ $message->type === 'Offer' ? 'OFFER' : 'WANTED' }} on {{ $group->nameshort }}
                </mj-text>
                <mj-text font-size="20px" font-weight="bold" padding-bottom="0">
                    {{ $message->subject }}
                </mj-text>
            </mj-column>
        </mj-section>

        @if($imageUrl)
        <mj-section background-color="#ffffff" padding="0 20px">
            <mj-column>
                <mj-image
                    width="300px"
                    src="{{ $imageUrl }}"
                    alt="Photo"
                />
            </mj-column>
        </mj-section>
        @endif

        @if($messageText)
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text color="#666666">
                    {{ \Illuminate\Support\Str::limit($messageText, 500) }}
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $messageUrl }}">
                    @if($message->type === 'Offer')
                    I'm Interested!
                    @else
                    I Can Help!
                    @endif
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    You're receiving this because you're a member of {{ $group->nameshort }}.
                </mj-text>
            </mj-column>
        </mj-section>

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>
