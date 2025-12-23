<mjml>
  @include('emails.mjml.partials.head', ['preview' => $senderName . ' sent you a message'])
  <mj-body background-color="#ffffff">
    {{-- Header --}}
    <mj-section mj-class="bg-success" padding="20px">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" color="#ffffff" align="center">
          New message from {{ $senderName }}
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- RSVP banner if reply expected --}}
    @if($replyExpected)
    <mj-section mj-class="bg-danger" padding="12px 20px">
      <mj-column>
        <mj-text font-size="16px" font-weight="bold" color="#ffffff" align="center">
          ⏰ Reply requested - please respond
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Referenced item --}}
    @if($refMessage)
    <mj-section mj-class="bg-light" padding="15px 20px" border-bottom="2px solid #e9ecef">
      <mj-column>
        <mj-text font-size="13px" color="#666666" align="center" padding-bottom="4px">
          About your post
        </mj-text>
        <mj-text font-size="16px" font-weight="600" color="#333333" align="center">
          {{ $refMessage->subject }}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- New message section --}}
    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" font-weight="bold" mj-class="text-success" text-transform="uppercase" letter-spacing="1px">
          New message
        </mj-text>
      </mj-column>
    </mj-section>

    @if($chatMessage['isFromRecipient'])
    {{-- Recipient's own message - profile pic on right --}}
    <mj-section background-color="#ffffff" padding="8px 20px">
      <mj-column vertical-align="top">
        <mj-text padding="0 8px 0 0" line-height="1.6" align="right">
          <span style="font-weight: 600; color: #338808; font-size: 14px;">{{ $chatMessage['userName'] }} (you)</span>
          <br/>
          <span style="color: #000000; font-size: 18px; font-weight: 500;">{!! nl2br(e($chatMessage['text'])) !!}</span>
        </mj-text>
      </mj-column>
      <mj-column width="44px" vertical-align="top">
        <mj-image
          src="{{ $chatMessage['profileUrl'] }}"
          width="36px"
          height="36px"
          border-radius="18px"
          alt="{{ $chatMessage['userName'] }}"
          padding="0"
        />
      </mj-column>
    </mj-section>
    @else
    {{-- Other person's message - profile pic on left --}}
    <mj-section background-color="#ffffff" padding="8px 20px">
      <mj-column width="44px" vertical-align="top">
        <mj-image
          src="{{ $chatMessage['profileUrl'] }}"
          width="36px"
          height="36px"
          border-radius="18px"
          alt="{{ $chatMessage['userName'] }}"
          padding="0"
        />
      </mj-column>
      <mj-column vertical-align="top">
        <mj-text padding="0 0 0 8px" line-height="1.6">
          <span style="font-weight: 600; color: #333333; font-size: 14px;">{{ $chatMessage['userName'] }}</span>
          <br/>
          <span style="color: #000000; font-size: 18px; font-weight: 500;">{!! nl2br(e($chatMessage['text'])) !!}</span>
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    @if($chatMessage['imageUrl'])
    <mj-section background-color="#ffffff" padding="5px 20px 10px {{ $chatMessage['isFromRecipient'] ? '20px' : '52px' }}">
      <mj-column>
        <mj-image
          src="{{ $chatMessage['imageUrl'] }}"
          width="200px"
          alt="Shared image"
          padding="0"
        />
      </mj-column>
    </mj-section>
    @endif

    {{-- Reply button --}}
    <mj-section background-color="#ffffff" padding="25px 20px">
      <mj-column>
        <mj-button href="{{ $chatUrl }}" mj-class="btn-success" font-size="18px" padding="14px 40px">
          Reply to {{ $senderName }}
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Outcome buttons for OFFER items --}}
    @if($showOutcomeButtons && !empty($outcomeUrls))
    <mj-section mj-class="bg-light" padding="15px 20px">
      <mj-column>
        <mj-text font-size="13px" color="#666666" align="center" padding-bottom="10px">
          Has this item gone?
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 20px 20px">
      <mj-column width="50%">
        <mj-button href="{{ $outcomeUrls['taken'] ?? '#' }}" mj-class="btn-success" font-size="14px" padding="12px 20px" width="90%">
          Mark as TAKEN
        </mj-button>
      </mj-column>
      <mj-column width="50%">
        <mj-button href="{{ $outcomeUrls['withdrawn'] ?? '#' }}" background-color="#6c757d" color="#ffffff" font-size="14px" padding="12px 20px" width="90%">
          Withdraw post
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- Earlier conversation --}}
    @if($previousMessages->isNotEmpty())
    <mj-section mj-class="bg-light" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" font-weight="bold" color="#666666" text-transform="uppercase" letter-spacing="1px">
          Earlier in this conversation
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach($previousMessages as $prevMessage)
    <mj-section mj-class="bg-light" padding="8px 20px">
      <mj-column width="36px" vertical-align="top">
        <mj-image
          src="{{ $prevMessage['profileUrl'] }}"
          width="28px"
          height="28px"
          border-radius="14px"
          alt="{{ $prevMessage['userName'] }}"
          padding="0"
        />
      </mj-column>
      <mj-column vertical-align="top">
        <mj-text padding="0 0 0 8px" line-height="1.4">
          <span style="font-weight: 600; color: #555555; font-size: 13px;">{{ $prevMessage['userName'] }}</span>
          <span style="color: #888888; font-size: 12px;"> · {{ $prevMessage['formattedDate'] }}</span>
          <br/>
          <span style="color: #666666; font-size: 14px;">{!! nl2br(e($prevMessage['text'])) !!}</span>
        </mj-text>
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section mj-class="bg-light" padding="10px 20px 20px 20px">
      <mj-column>
        <mj-button href="{{ $chatUrl }}" background-color="transparent" mj-class="text-success" font-size="14px" padding="8px 20px" border="1px solid #338808">
          View full conversation
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- About sender --}}
    @if($sender && $sender->aboutme)
    <mj-section background-color="#ffffff" padding="20px" border-top="1px solid #e9ecef">
      <mj-column>
        <mj-text font-size="12px" font-weight="bold" color="#666666" text-transform="uppercase" letter-spacing="1px" padding-bottom="10px">
          About {{ $senderName }}
        </mj-text>
        <mj-text font-size="14px" color="#555555" font-style="italic" line-height="1.5">
          "{{ $sender->aboutme }}"
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Jobs section --}}
    @if($jobAds->isNotEmpty())
    <mj-section mj-class="bg-light" padding="20px 20px 10px 20px" border-top="1px solid #e9ecef">
      <mj-column>
        <mj-text font-size="16px" font-weight="bold" color="#333333" align="center" padding-bottom="10px">
          Jobs near you
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 10px 20px">
      <mj-column>
        @foreach($jobAds as $job)
        <mj-text font-size="14px" color="#333333" padding="5px 0">
          <a href="{{ config('freegle.sites.user') }}/job/{{ $job->id }}" style="color: #338808; font-weight: bold; text-decoration: none;">
            {{ $job->title }}@if($job->location) ({{ $job->location }})@endif
          </a>
        </mj-text>
        @endforeach
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" line-height="1.4">
          If you are interested and click, it will raise a little to help keep Freegle running and free to use.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 20px 20px">
      <mj-column width="50%">
        <mj-button href="{{ $jobsUrl }}" mj-class="btn-secondary" font-size="14px" padding="12px 20px" width="90%">
          View more jobs
        </mj-button>
      </mj-column>
      <mj-column width="50%">
        <mj-button href="{{ $donateUrl }}" mj-class="btn-success" font-size="14px" padding="12px 20px" width="90%">
          Donating helps too!
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', ['email' => $recipient->email_preferred, 'settingsUrl' => $settingsUrl])

    {{-- Tracking pixel --}}
    @if(!empty($trackingPixelHtml))
    <mj-section padding="0">
      <mj-column>
        <mj-text padding="0">{!! $trackingPixelHtml !!}</mj-text>
      </mj-column>
    </mj-section>
    @endif
  </mj-body>
</mjml>
