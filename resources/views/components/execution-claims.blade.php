<section data-verdict-console="execution-claims">
    @if ($claims === [])
        <p data-empty>No unresolved Verdict execution claims.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Capability</th><th>Policy</th><th>Status</th><th>Attempts</th><th>Fingerprint</th><th>Updated</th><th>Resolve</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($claims as $item)
                    <tr data-claim="{{ $item->id }}" data-status="{{ $item->status }}">
                        <td data-field="id">{{ $item->id }}</td>
                        <td data-field="capability">{{ $item->capability }}</td>
                        <td data-field="policy">{{ $item->policy }}</td>
                        <td data-field="status">{{ $item->status }}</td>
                        <td data-field="attempts">{{ $item->attemptCount }}</td>
                        <td data-field="fingerprint">{{ $item->fingerprint }}</td>
                        <td data-field="updated"><time datetime="{{ $item->updatedAt->format(DATE_ATOM) }}">{{ $item->updatedAt->format(DATE_ATOM) }}</time></td>
                        <td><code>verdict:resolve-execution-claim {{ $item->id }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
