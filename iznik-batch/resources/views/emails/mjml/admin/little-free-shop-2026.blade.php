{{-- CROSS-REFERENCE: A pre-compiled HTML preview of this template exists at:
     iznik-nuxt3/modtools/components/ModAdminPreviewLittleFreeShop2026.vue
     If you modify this MJML template, you must regenerate that preview to match. --}}
<mjml>
    @include('emails.mjml.partials.head', ['preview' => $adminSubject])
    <mj-body background-color="#ffffff">

        {{-- Hero banner — photo with heading overlaid at bottom-left --}}
        <mj-section background-url="{{ $heroImageUrl }}" background-size="cover" background-position="center" padding="0">
            <mj-column>
                <mj-text font-size="28px" font-weight="bold" color="#ffffff" align="left" line-height="1.3" font-family="Helvetica, Arial, sans-serif" padding="180px 25px 10px 0">
                    <span style="background-color: rgba(0,0,0,0.5); padding: 6px 12px; display: inline;">{{ $heroHeading ?? $adminSubject }}</span>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- First paragraph + early CTA above the fold --}}
        @php
            $paragraphs = preg_split('/\n\s*\n/', $adminText, 2);
            $firstParagraph = $paragraphs[0] ?? '';
            $remainingText = $paragraphs[1] ?? '';
        @endphp
        <mj-section background-color="#ffffff" padding="20px 25px 10px">
            <mj-column>
                <mj-text font-size="15px" color="#333333" line-height="1.7" font-family="Helvetica, Arial, sans-serif">
                    {!! nl2br($firstParagraph) !!}
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Early donate button — above the fold --}}
        @if($ctaLink && $ctaText)
        <mj-section background-color="#ffffff" padding="5px 25px 10px">
            <mj-column>
                <mj-button href="{{ $ctaLink }}" background-color="#338808" color="#ffffff" font-size="18px" font-weight="bold" border-radius="6px" inner-padding="12px 35px" align="center" font-family="Helvetica, Arial, sans-serif">
                    {{ $ctaText }}
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        {{-- Remaining body text --}}
        @if($remainingText)
        <mj-section background-color="#ffffff" padding="10px 25px 10px">
            <mj-column>
                <mj-text font-size="15px" color="#333333" line-height="1.7" font-family="Helvetica, Arial, sans-serif">
                    {!! nl2br($remainingText) !!}
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Second donate button --}}
        @if($ctaLink && $ctaText)
        <mj-section background-color="#ffffff" padding="10px 25px 5px">
            <mj-column>
                <mj-button href="{{ $ctaLink }}" background-color="#338808" color="#ffffff" font-size="18px" font-weight="bold" border-radius="6px" inner-padding="12px 35px" align="center" font-family="Helvetica, Arial, sans-serif">
                    {{ $ctaText }}
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        {{-- Target --}}
        @if(isset($targetAmount) && $targetAmount)
        <mj-section background-color="#ffffff" padding="0 25px 10px">
            <mj-column>
                <mj-text font-size="13px" color="#888888" align="center" font-family="Helvetica, Arial, sans-serif">
                    target <span style="font-weight:bold; color:#333333;">{{ $targetAmount }}</span> — every pound gets us closer
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Divider --}}
        <mj-section background-color="#ffffff" padding="0 25px">
            <mj-column>
                <mj-divider border-color="#e0e0e0" border-width="1px" />
            </mj-column>
        </mj-section>

        {{-- Why it matters — green ticks, bold labels --}}
        @if(isset($bulletPoints) && count($bulletPoints) > 0)
        <mj-section background-color="#ffffff" padding="15px 25px 10px">
            <mj-column>
                <mj-text font-size="16px" font-weight="bold" color="#333333" padding-bottom="10px" font-family="Helvetica, Arial, sans-serif">
                    Why it matters:
                </mj-text>
                <mj-text font-size="14px" color="#333333" line-height="2.0" font-family="Helvetica, Arial, sans-serif">
                    @foreach($bulletPoints as $point)
                    <span style="color: #338808;">&#10004;</span>&nbsp;&nbsp;<strong>{{ Str::before($point, ' — ') }}</strong> — {{ Str::after($point, ' — ') }}<br/>
                    @endforeach
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Spending plan — muted, small, below Why it matters --}}
        @if(isset($spendingPlan) && $spendingPlan)
        <mj-section background-color="#ffffff" padding="0 25px 15px">
            <mj-column>
                <mj-text font-size="12px" color="#999999" line-height="1.6" font-family="Helvetica, Arial, sans-serif">
                    {!! $spendingPlan !!}
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Local volunteers --}}
        @if(isset($volunteers) && count($volunteers) > 0)
        <mj-section background-color="#ffffff" padding="10px 25px 15px">
            <mj-column>
                <mj-text font-size="13px" color="#4a5568" font-style="italic" align="center" font-family="Helvetica, Arial, sans-serif">
                    @if(count($volunteers) === 1)
                        Your local volunteer is {{ $volunteers[0]['firstname'] }}.
                    @elseif(count($volunteers) === 2)
                        Your local volunteers are {{ $volunteers[0]['firstname'] }} and {{ $volunteers[1]['firstname'] }}.
                    @else
                        Your local volunteers are {{ collect($volunteers)->slice(0, -1)->pluck('firstname')->implode(', ') }}, and {{ $volunteers[count($volunteers) - 1]['firstname'] }}.
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Footer with opt-out, unsubscribe, and charity info --}}
        <mj-section background-color="#f5f5f5" padding="20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666" align="center" line-height="1.6" font-family="Helvetica, Arial, sans-serif">
                    @if($marketingOptOutUrl)
                    Don't want to receive these emails? <a href="{{ $marketingOptOutUrl }}" style="color: #338808;">Click here to opt out</a>
                    <br/>
                    @endif
                    <a href="{{ $settingsUrl ?? config('freegle.sites.user') . '/settings' }}" style="color: #338808;">Change your email settings</a> &bull;
                    <a href="{{ $unsubscribeUrl ?? config('freegle.sites.user') . '/unsubscribe' }}" style="color: #338808;">Unsubscribe</a>
                </mj-text>
                <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
                <mj-text font-size="11px" color="#666666" align="center" line-height="1.5" font-family="Helvetica, Arial, sans-serif">
                    {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
                    Registered address: Weaver's Field, Loud Bridge, Chipping PR3 2NX
                </mj-text>
            </mj-column>
        </mj-section>

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>
