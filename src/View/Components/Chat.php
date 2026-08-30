<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Approvals\ApprovalInbox;
use Fissible\VerdictConsole\Chat\ChatService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Server-rendered, reload-driven chat thread with a scoped approval interrupt.
 *
 * Refusals are HttpExceptions rather than AuthorizationExceptions because Blade wraps the latter
 * in a ViewException, which a host exception handler would otherwise render as a 500.
 */
final class Chat extends Component
{
    private const string AUTHORIZATION_REFUSAL_MESSAGE = 'This participant may not use this conversation.';

    public function __construct(
        private ChatService $chat,
        private ApprovalInbox $inbox,
        private Guard $auth,
        private ?string $conversation = null,
    ) {}

    public function render(): View
    {
        $conversation = $this->conversation ?? session('verdict-console.chat.conversation');
        $thread = null;
        $hasInterrupt = false;

        if ($conversation !== null) {
            $user = $this->auth->user();

            if ($user === null) {
                throw new AccessDeniedHttpException(self::AUTHORIZATION_REFUSAL_MESSAGE);
            }

            // ChatService owns the indistinguishable foreign/unknown refusal; do not turn it into
            // an empty thread, which would leak a different result to an unauthorized reader.
            try {
                $thread = $this->chat->thread($user, $conversation);
            } catch (AuthorizationException) {
                throw new AccessDeniedHttpException(self::AUTHORIZATION_REFUSAL_MESSAGE);
            }
            // An indexed row survives a decision for audit/expiry rendering, but it no longer
            // interrupts the thread once Verdict's live read says it is not pending. A lapsed
            // receipt whose thread remains paused no longer interrupts it: its close verb is in
            // the inbox widget, while an inline close remains a possible follow-up.
            foreach ($this->inbox->itemsForConversation($conversation) as $item) {
                if ($item->state === 'pending') {
                    $hasInterrupt = true;

                    break;
                }
            }
        }

        return view('verdict-console::components.chat', [
            'conversation' => $conversation,
            'hasInterrupt' => $hasInterrupt,
            'mounted' => Route::has('verdict-console.chat.send'),
            'thread' => $thread,
        ]);
    }
}
