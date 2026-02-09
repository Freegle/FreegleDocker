<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'External donation received'])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'External Donation'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p><strong>{{ $userName }}</strong> (#{{ $userId }}, {{ $userEmail }}) donated &pound;{{ $amount }} via an external donation.</p>
        </mj-text>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>Please can you thank them?</p>
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          This is an automated notification from {{ $siteName }}.
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
