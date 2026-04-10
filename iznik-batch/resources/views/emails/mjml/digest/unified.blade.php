<mjml>
    @include('emails.mjml.partials.head', [
        'preview' => $postCount . ' new posts near you',
        'styles' => '
            .message-card { border-bottom: 1px solid #eeeeee; padding-bottom: 15px; margin-bottom: 15px; }
            .message-title { font-weight: bold; color: #333333; }
            .message-type { font-size: 12px; color: #338808; text-transform: uppercase; }
            .posted-to { font-size: 11px; color: #888888; font-style: italic; }
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
                <mj-button href="{{ $post['messageUrl'] }}" align="left" padding="10px 0" mj-class="btn-success" border-radius="3px">
                    View Post
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $browseUrl }}" mj-class="btn-secondary" border-radius="3px">
                    Browse All Posts
                </mj-button>
            </mj-column>
        </mj-section>

        @if($sponsors->isNotEmpty())
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" padding-bottom="5px" />
                <mj-text font-size="12px" color="#888888" font-style="italic" padding-bottom="5px">
                    Sponsored by:
                </mj-text>
            </mj-column>
        </mj-section>
        @foreach($sponsors as $sponsor)
        <mj-section background-color="#ffffff" padding="0 20px 10px">
            <mj-column width="80px" vertical-align="middle">
                @if($sponsor->imageurl)
                <mj-image
                    width="60px"
                    src="{{ $sponsor->imageurl }}"
                    alt="{{ $sponsor->name }}"
                    href="{{ $sponsor->linkurl }}"
                    border-radius="5px"
                />
                @endif
            </mj-column>
            <mj-column vertical-align="middle">
                <mj-text font-size="13px">
                    @if($sponsor->linkurl)
                    <a href="{{ $sponsor->linkurl }}" style="color: #338808; text-decoration: none; font-weight: bold;">{{ $sponsor->name }}</a>
                    @else
                    <strong>{{ $sponsor->name }}</strong>
                    @endif
                    @if($sponsor->tagline)
                    <br /><span style="font-size: 11px; color: #666;">{{ $sponsor->tagline }}</span>
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach
        @endif

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    You're receiving this because you're a member of Freegle. These emails are sent daily.
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
