<mj-section background-color="#f4f4f4" padding="20px">
    <mj-column>
        <mj-text font-size="12px" color="#666666" align="center">
            This email was sent by <a href="{{ $userSite ?? config('freegle.sites.user') }}">{{ $siteName ?? config('freegle.branding.name') }}</a>.
        </mj-text>
        <mj-text font-size="12px" color="#666666" align="center">
            @if(isset($settingsUrl))
            <a href="{{ $settingsUrl }}">Change email settings</a> |
            @endif
            <a href="{{ $userSite ?? config('freegle.sites.user') }}/unsubscribe">Unsubscribe</a>
        </mj-text>
        <mj-text font-size="11px" color="#999999" align="center">
            {{ config('freegle.branding.name') }} is a registered charity in England and Wales (1168648).
        </mj-text>
    </mj-column>
</mj-section>
