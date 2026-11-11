<h1>{{ $title }}</h1>
<p>{{ $filterText }}<br>Generated {{ $generated }}</p>
<p>
    @foreach ($summary as $label => $value)
        <strong>{{ $label }}</strong> {{ is_int($value) ? number_format($value) : $value }}@unless($loop->last) &nbsp; · &nbsp; @endunless
    @endforeach
</p>
<p>{{ $note }}</p>
