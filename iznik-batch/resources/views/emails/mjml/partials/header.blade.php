<mj-section background-url="{{ config('freegle.branding.wallpaper_url') }}" background-color="#e5e4db" border-top="5px solid #61AE24" padding="0px">
  <mj-group>
    <mj-column vertical-align="middle" width="65%">
      <mj-text color="#61AE24" font-size="18px"><b>{{ $title ?? config('freegle.branding.name') }}</b></mj-text>
    </mj-column>
    <mj-column vertical-align="middle" width="35%">
      <mj-image css-class="logo" src="{{ config('freegle.branding.logo_url') }}" alt="Logo" width="80px" align="right" border-radius="5px" padding="20px"></mj-image>
    </mj-column>
  </mj-group>
</mj-section>
