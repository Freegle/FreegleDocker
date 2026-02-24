<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Thank you for making the world a better place!'])
  <mj-body background-color="#f8f9ff">

    {{-- Festive header with nature theme --}}
    <mj-section background-color="#338808" padding="30px 20px">
      <mj-column>
        <mj-text align="center" font-size="40px" padding="0">
          🌍🌱🌻🌳♻️
        </mj-text>
        <mj-text align="center" font-size="28px" font-weight="bold" color="#ffffff" padding="15px 0 5px 0" line-height="1.2">
          Thank You, Pledger!
        </mj-text>
        <mj-text align="center" font-size="16px" color="#d4edda" padding="0">
          You helped make 2025 a greener year
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Main message --}}
    <mj-section background-color="#ffffff" padding="30px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Hi {{ $firstName }},
        </mj-text>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          At the start of 2025 you did something brilliant &mdash; you took the Freegle Pledge and
          promised to give away at least one item every month. That's a big commitment, and we
          wanted to say a proper <strong>thank you</strong>.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Months badge --}}
    @if($monthsFreegled > 0)
    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text align="center" padding="10px 0">
          <div style="background: linear-gradient(135deg, #338808, #5cb85c); color: white; font-weight: bold; padding: 20px 40px; border-radius: 50px; display: inline-block; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">
            <div style="font-size: 36px; margin-bottom: 4px;">{{ $monthsFreegled }}</div>
            <div style="font-size: 14px; letter-spacing: 2px;">{{ $monthsFreegled === 1 ? 'MONTH' : 'MONTHS' }} OF FREEGLING</div>
          </div>
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Personalised message based on months --}}
    <mj-section background-color="#ffffff" padding="0 20px 10px 20px">
      <mj-column>
        @if($monthsFreegled >= 10)
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Wow &mdash; <strong>{{ $monthsFreegled }} months</strong>! You absolutely smashed it. You're a
          freegling superstar and your commitment throughout the year has been incredible. Every single
          item you gave away found a new home instead of ending up in landfill. That's something to
          be really proud of.
        </mj-text>
        @elseif($monthsFreegled >= 5)
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          <strong>{{ $monthsFreegled }} months</strong> of freegling &mdash; that's fantastic! Life gets busy and
          it's not always easy to keep going, but you stuck with it and made a real difference.
          Every item you gave away helped someone out and kept something useful out of the bin.
        </mj-text>
        @elseif($monthsFreegled >= 1)
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          You freegled in <strong>{{ $monthsFreegled }} {{ $monthsFreegled === 1 ? 'month' : 'months' }}</strong>
          &mdash; and that matters. Even one month of giving things away instead of throwing them out
          makes a difference. You helped someone get something they needed, and you kept it out of landfill.
          That's a win all round.
        </mj-text>
        @else
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Just by signing up you showed you care about reducing waste and helping your community.
          That positive intention counts, and we hope you'll keep freegling whenever you can.
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    {{-- Impact section --}}
    <mj-section background-color="#f0f7e6" padding="25px 20px" border-radius="8px">
      <mj-column>
        <mj-text align="center" font-size="18px" font-weight="bold" color="#338808" padding="0 0 10px 0">
          Together, our pledgers have:
        </mj-text>
        <mj-text align="center" font-size="15px" color="#333333" line-height="1.8" padding="0">
          🏠 Given thousands of items a new home<br/>
          🗑️ Kept tonnes of stuff out of landfill<br/>
          💚 Helped neighbours across the country<br/>
          🌍 Reduced waste, one freegle at a time
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Forward-looking message --}}
    <mj-section background-color="#ffffff" padding="25px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          The Freegle Pledge campaign is wrapping up, but freegling never stops! Whenever you've got
          something you no longer need, pop it on Freegle and make someone's day.
        </mj-text>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Thank you for being part of something good. You've helped make the world a little bit better,
          and we think that's pretty wonderful.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- CTA button --}}
    <mj-section background-color="#ffffff" padding="0 20px 30px 20px">
      <mj-column>
        <mj-button href="{{ $userSite }}"
                   background-color="#338808"
                   color="white"
                   border-radius="25px"
                   padding="15px 10px"
                   font-weight="bold"
                   font-size="16px">
          Keep Freegling!
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Sign-off --}}
    <mj-section background-color="#ffffff" padding="0 20px 30px 20px">
      <mj-column>
        <mj-text align="center" font-size="20px" padding="5px 0">
          🌱💚🌍
        </mj-text>
        <mj-text align="center" font-size="14px" color="#666666" line-height="1.5">
          With huge thanks from all of us at Freegle,<br/>
          <strong>The Freegle Volunteers</strong>
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl])

  </mj-body>
</mjml>
