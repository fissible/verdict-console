@foreach ($chains as $chain)
    @if ($chain->state->value !== 'not_applicable')
        <section data-verdict-console="integrity" data-chain="{{ $chain->chainId }}" data-state="{{ $chain->state->value }}">
            @if ($chain->state->value === 'unnameable')
                <p>{{ $chain->unnameableReason?->value === 'invalid_topology' ? 'Chain configuration is invalid; integrity cannot be reported.' : 'Chained through a resolver; no chains are named for integrity reporting.' }}</p>
            @elseif ($chain->state->value === 'unverified')
                <p>Not yet verified.</p>
            @elseif ($chain->state->value === 'verified')
                <p>Verified through sequence {{ $chain->lastCompleted?->verifiedThroughSeq }} at {{ $chain->lastCompleted?->ranAt->format(DATE_ATOM) }}{{ $chain->lastCompleted?->source === 'recorded' ? ', as recorded by ' : ' by ' }}{{ $chain->lastCompleted?->ranBy }}.</p>
            @elseif ($chain->state->value === 'failed')
                <p>Verification failed at sequence {{ $chain->lastCompleted?->brokenAtSeq }} ({{ $chain->lastCompleted?->attestOutcome }}) at {{ $chain->lastCompleted?->ranAt->format(DATE_ATOM) }}.</p>
            @endif

            @if ($chain->lastAttempt?->outcome === 'errored' && $chain->lastAttempt?->ranAt != $chain->lastCompleted?->ranAt)
                <p>Last verification attempt errored at {{ $chain->lastAttempt->ranAt->format(DATE_ATOM) }}.</p>
            @endif

            @if ($chain->gaps !== null)
                <p>{{ $chain->gaps->persistedMarks }} chain-write gap marks (latest {{ $chain->gaps->latestMarkAt?->format(DATE_ATOM) }}).</p>
            @endif
        </section>
    @endif
@endforeach
