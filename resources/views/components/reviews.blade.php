<section data-review-queue>
    @if ($queue->state === \Fissible\VerdictConsole\Reviews\ReviewQueueState::Unconfigured)
        <p>The review lane is not configured.</p>
    @elseif ($queue->state === \Fissible\VerdictConsole\Reviews\ReviewQueueState::Unscoped)
        <p>No review scope is configured.</p>
    @else
        @forelse ($queue->items as $item)
            <article data-review-request="{{ $item->requestId }}">
                <p>{{ $item->capability }}</p>
                @if ($item->reason !== null)
                    <p>{{ $item->reason }}</p>
                @endif
                @if ($item->summaryFingerprint !== null)
                    <p>{{ $item->summaryFingerprint }}</p>
                @endif
                @if ($item->state === \Fissible\VerdictConsole\Reviews\ReviewItemState::LapsedUndecided)
                    <p>lapsed, undecided</p>
                @endif
                @foreach ($verbs->resolve($item) as $verb)
                    <button type="button" data-review-verb="{{ $verb->value }}">{{ ucfirst($verb->value) }}</button>
                @endforeach
            </article>
        @empty
            <p>No reviews are waiting.</p>
        @endforelse
    @endif
</section>
