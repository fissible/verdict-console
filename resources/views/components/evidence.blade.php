<section data-verdict-console="evidence" data-recording="{{ $result->recording->value }}" data-page="{{ $page }}" data-pages="{{ $pages }}"@if ($conversation !== null) data-conversation="{{ $result->conversation?->value ?? 'unknown' }}"@endif>
    @if ($result->recording->value === 'off')
        <p data-recording-off>recording is off — blank by config.</p>
    @elseif ($result->recording->value === 'elsewhere')
        <p data-recording-elsewhere>Evidence is recorded elsewhere by {{ $result->recordedBy }}.</p>
    @elseif ($conversation !== null && $result->conversation?->value === 'unknown')
        <p data-conversation-unknown>This conversation was never observed by the console.</p>
    @elseif ($result->records === [])
        <p data-empty>No decisions have been recorded.</p>
    @else
        <table data-evidence>
            <thead>
                <tr>
                    <th>Recorded at</th><th>Capability</th><th>Stage</th><th>Disposition</th><th>Claim type</th><th>Record digest</th><th>Invocation ID</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pageRecords as $record)
                    <tr data-record="{{ $record->id }}" data-disposition="{{ $record->disposition }}">
                        <td data-field="recorded_at"><time datetime="{{ $record->recordedAt->format(DATE_ATOM) }}">{{ $record->recordedAt->format(DATE_ATOM) }}</time></td>
                        <td data-field="capability">{{ $record->capability }}</td>
                        <td data-field="stage">{{ $record->stage }}</td>
                        <td data-field="disposition">{{ $record->disposition }}</td>
                        <td data-field="claim_type">{{ $record->claimType }}</td>
                        <td data-field="record_digest">{{ $record->recordDigest }}</td>
                        <td data-field="invocation_id">{{ $record->invocationId }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($pages > 1)
            <nav data-pagination>
                @for ($number = 1; $number <= $pages; $number++)
                    @if ($number !== $page)
                        <a data-page-link="{{ $number }}" href="{{ request()->fullUrlWithQuery(['page' => $number]) }}">{{ $number }}</a>
                    @else
                        <span>{{ $number }}</span>
                    @endif
                @endfor
            </nav>
        @endif
    @endif
</section>
