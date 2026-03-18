<mjml>
    <mj-head>
        <mj-attributes>
            <mj-all font-family="Arial, sans-serif" />
            <mj-text font-size="14px" color="#333333" line-height="1.5" />
        </mj-attributes>
        <mj-style inline="inline">
            a { color: #5cb85c; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </mj-style>
        <mj-title>Message from {{ $groupName }} volunteers</mj-title>
    </mj-head>
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    {!! nl2br(e($body)) !!}
                </mj-text>
            </mj-column>
        </mj-section>

        @include('emails.mjml.partials.footer', ['email' => $email ?? ''])
    </mj-body>
</mjml>
