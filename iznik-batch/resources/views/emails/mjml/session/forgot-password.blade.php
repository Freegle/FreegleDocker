<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Reset your Freegle password'])

  <mj-body background-color="#ffffff">
    @include('emails.mjml.partials.header', ['title' => 'Forgot your password?'])

    <mj-section padding="30px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6" align="center">
          No problem! Click the button below to log in and set a new password.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section padding="10px 20px 30px 20px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $resetUrl }}" border-radius="4px" font-size="18px" padding="14px 40px">
          Set a new password
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section padding="0 20px 20px 20px">
      <mj-column>
        <mj-text font-size="13px" line-height="1.5" color="#666666" align="center">
          If you didn't request this, you can safely ignore this email.
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
