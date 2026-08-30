<section data-verdict-console="incidents">
    @if ($incidents === [])
        <p data-empty>No incidents recorded.</p>
    @else
        <table>
            <thead>
                <tr><th>Source</th><th>Cause</th><th>Observed</th></tr>
            </thead>
            <tbody>
                @foreach ($incidents as $incident)
                    <tr data-incident="{{ $incident->id }}" data-source="{{ $incident->source }}">
                        <td data-field="source">{{ $incident->source }}</td>
                        <td data-field="cause">{{ $incident->cause }}</td>
                        <td data-field="observed"><time datetime="{{ $incident->observed_at->utc()->format(DATE_ATOM) }}">{{ $incident->observed_at->utc()->format(DATE_ATOM) }}</time></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
