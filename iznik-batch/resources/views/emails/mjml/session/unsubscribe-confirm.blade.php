<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Please confirm you want to leave Freegle'])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'Leaving Freegle?'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>Please click the button below to confirm you want to leave Freegle.</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $unsubUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          Click here to leave Freegle
        </mj-button>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p><strong>This will remove all your data and cannot be undone.</strong></p>
          <p>If you just want to leave a Freegle community or reduce the number of emails you get, please sign in and go to Settings instead.</p>
        </mj-text>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>If you didn't try to leave, please ignore this email.</p>
          <p>Thanks for freegling, and do please come back in the future.</p>
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
