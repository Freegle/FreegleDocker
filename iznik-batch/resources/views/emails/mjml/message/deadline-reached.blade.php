<mjml>
    <mj-head>
        <mj-attributes>
            <mj-all font-family="Arial, sans-serif" />
            <mj-text font-size="14px" color="#333333" line-height="1.5" />
            <mj-button background-color="#5cb85c" color="#ffffff" font-size="14px" />
        </mj-attributes>
        <mj-style inline="inline">
            a { color: #5cb85c; text-decoration: none; }
            a:hover { text-decoration: underline; }
            .action-button { margin: 5px 0; }
        </mj-style>
        <mj-title>Your post has reached its deadline</mj-title>
    </mj-head>
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold">
                    Your post has reached its deadline
                </mj-text>
                <mj-text>
                    Hi {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    Your post "<strong>{{ $message->subject }}</strong>" has now reached the deadline you set.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-weight="bold">
                    What would you like to do?
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column width="33%">
                <mj-button href="{{ $extendUrl }}" background-color="#337ab7" css-class="action-button">
                    Extend Deadline
                </mj-button>
            </mj-column>
            <mj-column width="33%">
                <mj-button href="{{ $completedUrl }}" background-color="#5cb85c" css-class="action-button">
                    Mark as {{ ucfirst($outcomeType) }}
                </mj-button>
            </mj-column>
            <mj-column width="33%">
                <mj-button href="{{ $withdrawUrl }}" background-color="#d9534f" css-class="action-button">
                    Withdraw
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666">
                    <strong>Extend:</strong> Give yourself more time to find someone for your item.<br />
                    <strong>Mark as {{ ucfirst($outcomeType) }}:</strong> Tell us the item has found a new home.<br />
                    <strong>Withdraw:</strong> Remove the post if you no longer want to give it away.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    This message was sent because your post on {{ $groupName }} reached the deadline you set.
                </mj-text>
            </mj-column>
        </mj-section>

        @include('emails.mjml.components.footer')
    </mj-body>
</mjml>
