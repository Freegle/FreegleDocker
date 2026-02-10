<mjml>
  @include('emails.mjml.partials.head', ['preview' => $senderName . ' has invited you to try Freegle!'])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'You\'re Invited!'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p><strong>{{ $senderName }}</strong> ({{ $senderEmail }}) has invited you to try Freegle!</p>
        </mj-text>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>Got stuff you don't need? Freegle helps you find someone to come and take it. Looking for something? We'll pair you with someone giving it away.</p>
          <p>It's free to join, free to use, and everything on it is free.</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $inviteUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          Try Freegle
        </mj-button>

        <mj-text font-size="12px" line-height="1.5" padding="10px 25px" color="#666666">
          <p>{{ $senderEmail }} said they knew you - but if you don't know them, or don't want any more of these invitations, please mail <a href="mailto:{{ config('freegle.mail.geeks_addr') }}">{{ config('freegle.mail.geeks_addr') }}</a>.</p>
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          {{ $siteName }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
