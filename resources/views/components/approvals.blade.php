<section data-verdict-console="approvals" data-routes="{{ $mounted ? 'mounted' : 'unmounted' }}">
    @forelse ($items as $item)
        @php
            $state = $item->resumability !== 'drivable' ? 'not_console_actionable' : $item->state;
            $presentation = $item->presentation ?? [];
            $capability = $presentation['capability'] ?? $item->capability;
        @endphp
        <article data-approval="{{ $item->id }}" data-state="{{ $state }}"@if ($item->unresumableReason !== null) data-unresumable-reason="{{ $item->unresumableReason }}"@endif>
            <h2>{{ $presentation['tool'] ?? $item->toolCallId }}</h2>
            @if ($capability !== null)
                <p>{{ $capability }}</p>
            @endif
            @if (isset($presentation['arguments_fingerprint']))
                <p>{{ $presentation['arguments_fingerprint'] }}</p>
            @endif
            @if (($presentation['details'] ?? []) !== [])
                <dl>
                    @foreach ($presentation['details'] as $name => $value)
                        <dt>{{ $name }}</dt><dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            @endif
            @if ($item->reason !== null)
                <dl><dt>Why this capability is gated</dt><dd>{{ $item->reason }}</dd></dl>
            @endif
            @if ($state === 'pending' && $item->expiresAt !== null)
                <time datetime="{{ $item->expiresAt->format(DATE_ATOM) }}">{{ $item->expiresAt->format(DATE_ATOM) }}</time>
            @elseif ($state === 'expired_or_already_decided')
                <p>expired or already decided</p>
            @elseif ($state === 'not_console_actionable')
                <p>not actionable from this console: <code>{{ $item->unresumableReason }}</code></p>
            @endif
            <div data-provenance="{{ $item->provenance['state'] }}">
                @if ($item->provenance['state'] === 'declared')
                    <ul>
                        @foreach ($item->provenance['sources'] as $source)
                            <li data-source-warning="{{ $source['warning'] ? 'true' : 'false' }}">{{ $source['kind'] }} · {{ $source['name'] }} · {{ $source['trust'] }} · {{ $source['data_class'] }} · {{ $source['channel'] }}</li>
                        @endforeach
                    </ul>
                    @if ($item->provenance['undescribed_source_count'] > 0)
                        <p>{{ $item->provenance['undescribed_source_count'] }} upstream source{{ $item->provenance['undescribed_source_count'] === 1 ? '' : 's' }} undescribed</p>
                    @endif
                    @if ($item->provenance['withheld_source_count'] > 0)
                        <p>{{ $item->provenance['withheld_source_count'] }} upstream source{{ $item->provenance['withheld_source_count'] === 1 ? '' : 's' }} withheld by release policy</p>
                    @endif
                @else
                    <p>{{ $item->provenance['message'] }}</p>
                @endif
            </div>
            @if ($state === 'not_console_actionable')
            @elseif (! $mounted && $item->verbs !== [])
                <p data-actions-unavailable>Actions are unavailable: the console routes are not registered.</p>
            @elseif ($mounted)
                @foreach ($item->verbs as $verb)
                    <form method="post" action="{{ route($routes[$verb->value], $item->id) }}" data-verb="{{ $verb->value }}">
                        @csrf
                        <button type="submit">{{ ucfirst($verb->value) }}</button>
                    </form>
                @endforeach
            @endif
        </article>
    @empty
        <p data-empty>No approvals are waiting.</p>
    @endforelse
</section>
