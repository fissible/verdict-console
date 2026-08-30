<section data-verdict-console="chat" data-conversation="{{ $conversation ?? '' }}" data-routes="{{ $mounted ? 'mounted' : 'unmounted' }}" data-streaming="false">
    <ol data-messages>
        @foreach ($thread?->messages ?? [] as $message)
            @if (in_array($message->role, ['user', 'assistant'], true) && $message->content !== null && $message->content !== '')
                <li data-role="{{ $message->role }}">{{ $message->content }}</li>
            @endif
        @endforeach
    </ol>

    @if ($hasInterrupt)
        <div data-interrupt><x-verdict-console::approvals :conversation="$conversation" /></div>
    @endif

    @if ($mounted)
        <form method="post" action="{{ route('verdict-console.chat.send') }}" data-chat-form>
            @csrf
            @if ($conversation !== null)
                <input type="hidden" name="conversation" value="{{ $conversation }}">
            @endif
            <textarea name="prompt"></textarea><button type="submit">Send</button>
        </form>
    @else
        <p data-actions-unavailable>Actions are unavailable: the console routes are not registered.</p>
    @endif

    <p data-limitation>This thread does not stream; it updates when the page reloads.</p>
</section>
