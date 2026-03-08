<mjml>
    @include('emails.mjml.partials.head', ['preview' => $adminSubject])
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="18px" font-weight="bold" color="#333333" padding-bottom="10px">
                    {{ $adminSubject }}
                </mj-text>
                <mj-text font-size="14px" color="#333333" line-height="1.6">
                    {!! nl2br(e($adminText)) !!}
                </mj-text>
            </mj-column>
        </mj-section>

        @if($ctaLink && $ctaText)
        <mj-section background-color="#ffffff" padding="10px 20px 20px">
            <mj-column>
                <mj-button mj-class="btn-success" href="{{ $ctaLink }}" font-size="16px" padding="10px 0">
                    {{ $ctaText }}
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        @if($groupName)
        <mj-section background-color="#f8f9fa" padding="15px 20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666" line-height="1.5">
                    This message was sent to members of <strong>{{ $groupName }}</strong> on {{ config('freegle.branding.name') }}.
                    @if($modsEmail)
                    <br/>You can contact your group volunteers at <a href="mailto:{{ $modsEmail }}" style="color: #338808;">{{ $modsEmail }}</a>.
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- TODO: V1 renders group sponsorship logos here from the groups_sponsorship table.
             Implement when sponsorship data is available via a Laravel model. --}}

        @if($marketingOptOutUrl)
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-size="11px" color="#999999" align="center">
                    Don't want to receive these emails? <a href="{{ $marketingOptOutUrl }}" style="color: #999999; text-decoration: underline;">Click here to opt out</a>
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>
