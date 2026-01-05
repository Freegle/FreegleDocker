{{-- Modern clean header with Freegle brand colors --}}
<mj-section background-color="#338808" padding="15px 20px">
    <mj-column vertical-align="middle" width="70%">
        <mj-text color="#ffffff" font-size="20px" font-weight="bold" padding="0">
            {{ $title ?? config('freegle.branding.name') }}
        </mj-text>
    </mj-column>
    <mj-column vertical-align="middle" width="30%">
        <mj-image
            width="60px"
            src="{{ $logoUrl ?? config('freegle.branding.logo_url') }}"
            alt="{{ $siteName ?? config('freegle.branding.name') }}"
            align="right"
            border-radius="8px"
            padding="0"
        />
    </mj-column>
</mj-section>
