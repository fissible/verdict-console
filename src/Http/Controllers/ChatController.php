<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Http\Controllers;

use Fissible\VerdictConsole\Chat\ChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Relays a non-streaming thread form post to the host-owned chat service. */
final readonly class ChatController
{
    public function __construct(private ChatService $chat) {}

    public function send(Request $request): RedirectResponse
    {
        abort_if($request->user() === null, 403);

        $validated = $request->validate([
            'prompt' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim((string) $value) === '') {
                        $fail('The '.$attribute.' field must not be blank.');
                    }
                },
            ],
            'conversation' => ['nullable', 'string'],
        ]);

        $turn = array_key_exists('conversation', $validated) && $validated['conversation'] !== null
            ? $this->chat->continue($request->user(), $validated['conversation'], $validated['prompt'])
            : $this->chat->start($request->user(), $validated['prompt']);

        return redirect()->back()
            ->with('verdict-console.chat.conversation', $turn->conversationId)
            ->with('verdict-console.status', $turn->paused ? 'paused' : 'sent');
    }
}
