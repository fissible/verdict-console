<section data-verdict-console="configuration-drift" data-recording="{{ $result->recording->value }}">
    @if ($result->recording->value === 'off')
        <p data-recording-off>recording is off — blank by config.</p>
    @elseif ($result->recording->value === 'chained')
        <p data-recording-chained>A chained sink{{ $result->recordedBy === null ? '' : ' ('.$result->recordedBy.')' }} is configured; decisions are not readable from this table.</p>
    @elseif ($result->recording->value === 'elsewhere')
        <p data-recording-elsewhere>Evidence is recorded elsewhere by {{ $result->recordedBy }}.</p>
    @elseif ($result->observations === [])
        <p data-empty>No configuration observations have been recorded.</p>
    @else
        <table data-configuration-drift>
            <tbody>
                @foreach ($result->observations as $observation)
                    <tr data-observation data-capability="{{ $observation->capability }}" data-fingerprint="{{ $observation->configurationFingerprint }}" data-current="{{ ($currentFingerprints[$observation->capability] ?? null) === $observation->configurationFingerprint ? 'true' : 'false' }}">
                        <td data-field="first_observed"><time datetime="{{ $observation->firstObservedAt->format(DATE_ATOM) }}">{{ $observation->firstObservedAt->format(DATE_ATOM) }}</time></td>
                        <td data-field="last_observed"><time datetime="{{ $observation->lastObservedAt->format(DATE_ATOM) }}">{{ $observation->lastObservedAt->format(DATE_ATOM) }}</time></td>
                        <td data-field="decisions">{{ $observation->decisionCount }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p>Observed history, not a write log: a configuration change that never decided anything leaves no row here.</p>
</section>
