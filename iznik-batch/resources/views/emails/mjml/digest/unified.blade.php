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
            .posted-to { font-size: 11px; color: #888888; font-style: italic; }
            a { color: #5cb85c; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </mj-style>
        <mj-title>{{ $postCount }} new posts near you</mj-title>
    </mj-head>
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Hi {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    Here {{ $postCount === 1 ? 'is' : 'are' }} <strong>{{ $postCount }}</strong> new post{{ $postCount === 1 ? '' : 's' }} from your Freegle communities:
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach($posts as $post)
        <mj-section background-color="#ffffff" padding="10px 20px" css-class="message-card">
            <mj-column width="25%">
                @if($post['imageUrl'])
                <mj-image
                    width="80px"
                    src="{{ $post['imageUrl'] }}"
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
                    {{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}
                </mj-text>
                <mj-text css-class="message-title">
                    <a href="{{ $post['messageUrl'] }}">{{ $post['itemName'] }}</a>
                </mj-text>
                @if($post['messageText'])
                <mj-text font-size="13px" color="#666666">
                    {{ \Illuminate\Support\Str::limit($post['messageText'], 100) }}
                </mj-text>
                @endif
                @if($post['postedToText'])
                <mj-text css-class="posted-to">
                    {{ $post['postedToText'] }}
                </mj-text>
                @endif
                <mj-button href="{{ $post['messageUrl'] }}" align="left" padding="10px 0">
                    View Post
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $browseUrl }}" background-color="#337ab7">
                    Browse All Posts
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    You're receiving this because you're a member of Freegle. These emails are sent daily.
                </mj-text>
            </mj-column>
        </mj-section>

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>
