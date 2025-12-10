<mj-section background-color="#5cb85c" padding="20px">
    <mj-column>
        <mj-image
            width="120px"
            src="{{ $logoUrl ?? config('freegle.branding.logo_url') }}"
            alt="{{ $siteName ?? config('freegle.branding.name') }}"
        />
    </mj-column>
</mj-section>
