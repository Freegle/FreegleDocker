<mj-head>
  <mj-preview>{{ $preview ?? '' }}</mj-preview>
  <mj-style>
    a { color: #338808; text-decoration: none; font-weight: bold }
    ol { margin-top: 0; margin-bottom: 0; padding-left: 2.4em; }
    li { margin: 0.5em 0; }
  </mj-style>
  <mj-attributes>
    <mj-all font-family="Trebuchet MS, Helvetica, Arial"></mj-all>
    {{-- Freegle brand colors from website --}}
    <mj-class name="bg-success" background-color="#338808" />
    <mj-class name="bg-secondary" background-color="#00A1CB" />
    <mj-class name="bg-header" background-color="#1d6607" />
    <mj-class name="bg-warning" background-color="#e38d13" />
    <mj-class name="bg-light" background-color="#f8f9fa" />
    <mj-class name="bg-green-light" background-color="#f0f7e6" />
    <mj-class name="text-success" color="#338808" />
    <mj-class name="text-header" color="#1d6607" />
    <mj-class name="btn-success" background-color="#338808" color="white" font-weight="bold" />
    <mj-class name="btn-secondary" background-color="#00A1CB" color="white" font-weight="bold" />
  </mj-attributes>
</mj-head>
