<mjml>
  @include('emails.mjml.partials.head')
  <mj-body>
    <mj-wrapper padding="0px" full-width="full-width">
      @include('emails.mjml.partials.header', ['title' => 'Thanks for joining ' . config('freegle.branding.name')])
      <mj-section background-color="#F7F6EC">
        <mj-column>
          @if($password)
          <mj-text font-weight="700">Here's your password: {{ $password }}.</mj-text>
          @endif
          <mj-text>We could give you a ten page long Terms of Use. Like you'd read that.  As far as we're concerned, it's simple...
            <ol>
              <li>Use {{ config('freegle.branding.name') }} to pass on unwanted items direct to others.</li>
              <li>Everything must be free and legal.</li>
              <li>Be nice to other freeglers, and they'll be nice to you.</li>
              <li>We don't see or check items offered - see our <a href="{{ config('freegle.sites.user') }}/disclaimer">disclaimer</a>.</li>
              <li>{{ config('freegle.branding.name') }} communities may have additional rules - please check the welcome mail you get when you join for details.</li>
              <li>Please <a href="{{ config('freegle.sites.user') }}/disclaimer">keep yourself safe and watch out for scams</a>.</li>
              <li>Objectionable content or behaviour will not be tolerated.  If you see anything inappropriate, suspicious or just dodgy then please <a href="{{ config('freegle.sites.user') }}/help">report it</a>.</li>
              <li>You must be aged 13 or over, as there is user-generated content.</li>
              <li>We keep your personal data private - see <a href="{{ config('freegle.sites.user') }}/privacy">privacy page</a>.</li>
              <li>Your posts will be public - see <a href="{{ config('freegle.sites.user') }}/privacy">privacy page</a>.</li>
              <li>We will email you posts, replies, events, newsletters, etc - control these in <a href="{{ config('freegle.sites.user') }}/settings">Settings</a>.</li>
            </ol>
          </mj-text>
          <mj-text font-size="14px"><b>Happy freegling!</b></mj-text>
          <mj-divider border-color="#d2cfb7" border-style="dotted"></mj-divider>
        </mj-column>
      </mj-section>
      <mj-section background-color="#FFFFFF">
        <mj-column width="55%">
          <mj-image fluid-on-mobile src="{{ config('freegle.images.welcome1') }}" width="280px" padding-bottom="20px" alt="" align="center" border="none"></mj-image>
        </mj-column>
        <mj-column width="45%" background-color="#FFFFFF">
          <mj-text align="left" padding=" 10px 10px" font-size="20px" line-height="30px" font-family="Alice, Helvetica, Arial, sans-serif">Post an OFFER</mj-text>
          <mj-text align="left" padding="0 10px">Give something away!  Upload a photo, share the info, enter your postcode and email, and you're ready to go!</mj-text>
          <mj-button href="{{ config('freegle.sites.user') }}/give" background-color="#325906" color="white" padding="20px 20px 30px 20px" border-radius="20px">POST AN OFFER</mj-button>
        </mj-column>
        <mj-divider padding="0 20px" border-width="1px" border-color="#000000"></mj-divider>
      </mj-section>
      <mj-section background-color="#FFFFFF">
        <mj-column width="55%">
          <mj-image fluid-on-mobile src="{{ config('freegle.images.welcome2') }}" width="280px" padding-bottom="20px" alt="" align="center" border="none"></mj-image>
        </mj-column>
        <mj-column width="45%" background-color="#FFFFFF">
          <mj-text align="left" padding=" 10px 10px" font-size="20px" line-height="30px" font-family="Alice, Helvetica, Arial, sans-serif">Post a WANTED</mj-text>
          <mj-text align="left" padding="0 10px">Ask the {{ config('freegle.branding.name') }} community for something you need.</mj-text>
          <mj-button href="{{ config('freegle.sites.user') }}/find" background-color="#325906" color="white" padding="20px 20px 30px 20px" border-radius="20px">POST A WANTED</mj-button>
        </mj-column>
        <mj-divider padding="0 20px" border-width="1px" border-color="#000000"></mj-divider>
      </mj-section>
      <mj-section background-color="#FFFFFF">
        <mj-column width="55%">
          <mj-image fluid-on-mobile src="{{ config('freegle.images.welcome3') }}" width="280px" padding-bottom="20px" alt="" align="center" border="none"></mj-image>
        </mj-column>
        <mj-column width="45%" background-color="#FFFFFF">
          <mj-text align="left" padding=" 10px 10px" font-size="20px" line-height="30px" font-family="Alice, Helvetica, Arial, sans-serif">Join a community</mj-text>
          <mj-text align="left" padding="0 10px">Just browsing?  Find communities and sign up for email alerts here.</mj-text>
          <mj-button href="{{ config('freegle.sites.user') }}/explore" background-color="#325906" color="white" padding="20px 20px 30px 20px" border-radius="20px">EXPLORE COMMUNITIES</mj-button>
        </mj-column>
        <mj-divider padding="0 20px" border-width="1px" border-color="#000000"></mj-divider>
      </mj-section>
      @include('emails.mjml.partials.footer', ['email' => $email])
    </mj-wrapper>
  </mj-body>
</mjml>
