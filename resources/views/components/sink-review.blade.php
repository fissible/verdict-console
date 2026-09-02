<section data-verdict-console="sink-review" data-posture="{{ $reviewState }}">
    @if ($reviewState === 'off')
        <p>No evidence is being recorded, and no one has decided that.</p>
        <p>configuring the shipped attest recorder chains records written by later record() calls; it neither backfills nor makes pre-existing rows verifiable through the chain</p>
    @elseif ($reviewState === 'off_acknowledged')
        <p>Recording is off by explicit decision (verdict-console.evidence.accepted_off).</p>
        <p>configuring the shipped attest recorder chains records written by later record() calls; it neither backfills nor makes pre-existing rows verifiable through the chain</p>
    @elseif ($reviewState === 'on')
        <table data-evidence-sink>
            <tbody>
                <tr><th>Writer</th><td>{{ $posture->effectiveWriter }}</td></tr>
                <tr><th>Table</th><td>{{ $posture->table }}</td></tr>
                <tr><th>Connection</th><td>{{ $posture->connection }}</td></tr>
            </tbody>
        </table>
    @elseif ($reviewState === 'elsewhere')
        @if ($posture->recordedBy !== null)
            <p>Evidence is recorded elsewhere by {{ $posture->recordedBy }}.</p>
        @else
            <p>Evidence is recorded elsewhere; the writer is not nameable.</p>
        @endif
    @elseif ($reviewState === 'chained')
        <p>A chained sink{{ $posture->recordedBy === null ? '' : ' ('.$posture->recordedBy.')' }} is configured; decisions are not readable from this table.</p>
    @endif
</section>
