<mjml>
    @include('emails.mjml.partials.head', [
        'preview' => $post->subject,
        'styles' => '
            .message-type { font-size: 12px; color: #338808; text-transform: uppercase; font-weight: bold; }
        ',
    ])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text css-class="message-type">
                    {{ $post->type === 'Offer' ? 'OFFER' : 'WANTED' }} on {{ $group->nameshort }}
                </mj-text>
                <mj-text font-size="20px" font-weight="bold" padding-bottom="0">
                    {{ $post->subject }}
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
                <mj-button href="{{ $messageUrl }}" mj-class="btn-success" border-radius="3px">
                    @if($post->type === 'Offer')
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
