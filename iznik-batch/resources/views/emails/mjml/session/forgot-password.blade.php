<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Reset your Freegle password'])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'Forgot your password?'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>No problem! Just log in using the link below, and then set a new one. Happy freegling!</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $resetUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          Click here to set a new password
        </mj-button>
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
