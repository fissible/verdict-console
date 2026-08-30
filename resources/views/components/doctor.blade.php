<section data-verdict-console="doctor" data-errors="{{ $errors }}" data-warnings="{{ $warnings }}">
    @if ($findings === [])
        <p data-clean>Every console precondition is satisfied.</p>
    @else
        @foreach ($findings as $finding)
            <article data-finding="{{ $finding->code->value }}" data-severity="{{ $finding->severity->value }}">
                <p data-field="subject">{{ $finding->subject }}</p>
                <p data-field="summary">{{ $finding->summary }}</p>
                <p data-field="fix">{{ $finding->fix }}</p>
            </article>
        @endforeach
    @endif
</section>
