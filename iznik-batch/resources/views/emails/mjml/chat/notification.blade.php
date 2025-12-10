<mjml>
    <mj-head>
        <mj-attributes>
            <mj-all font-family="Arial, sans-serif" />
            <mj-text font-size="14px" color="#333333" line-height="1.5" />
            <mj-button background-color="#5cb85c" color="#ffffff" font-size="14px" />
        </mj-attributes>
        <mj-style inline="inline">
            .message-bubble { background-color: #f0f0f0; border-radius: 10px; padding: 10px 15px; margin-bottom: 10px; }
            .message-sender { font-weight: bold; color: #5cb85c; font-size: 12px; }
            .message-date { font-size: 11px; color: #999999; }
            a { color: #5cb85c; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </mj-style>
        <mj-title>{{ $messageCount }} new message{{ $messageCount === 1 ? '' : 's' }} on Freegle</mj-title>
    </mj-head>
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Hi {{ $recipient->displayname ?? 'there' }},
                </mj-text>
                @if($chatType === 'User2Mod')
                <mj-text>
                    You have {{ $messageCount === 1 ? 'a new message' : 'new messages' }} from volunteers at <strong>{{ $chatRoom->group->nameshort ?? 'your local Freegle group' }}</strong>:
                </mj-text>
                @else
                <mj-text>
                    @if($sender)
                    <strong>{{ $sender->displayname }}</strong> sent you {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }}:
                    @else
                    You have {{ $messageCount === 1 ? 'a new message' : $messageCount . ' new messages' }}:
                    @endif
                </mj-text>
                @endif
            </mj-column>
        </mj-section>

        @foreach($messages as $message)
        <mj-section background-color="#ffffff" padding="5px 20px">
            <mj-column>
                <mj-text css-class="message-bubble">
                    <span class="message-sender">{{ $message->user->displayname ?? 'Unknown' }}</span>
                    <span class="message-date">{{ $message->date->format('M j, g:i a') }}</span>
                    <br />
                    {{ $message->message }}
                </mj-text>
            </mj-column>
        </mj-section>

        @if($message->image)
        <mj-section background-color="#ffffff" padding="0 20px">
            <mj-column>
                <mj-image
                    width="200px"
                    src="{{ $userSite }}/img/{{ $message->imageid }}"
                    alt="Image"
                />
            </mj-column>
        </mj-section>
        @endif
        @endforeach

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $chatUrl }}">
                    Reply Now
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    This message was sent via Freegle. Please reply on the website to continue the conversation.
                </mj-text>
            </mj-column>
        </mj-section>

        @include('emails.mjml.components.footer')
    </mj-body>
</mjml>
