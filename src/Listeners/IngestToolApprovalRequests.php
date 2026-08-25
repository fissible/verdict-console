<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Listeners;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationDispatcher;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Fissible\VerdictConsole\Exceptions\ApprovalIngestionPersistenceFailed;
use Fissible\VerdictConsole\Exceptions\ApprovalReceiptCollision;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Ai\Approvals\PendingApproval as LaravelPendingApproval;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Models\Conversation;
use Psr\Log\LoggerInterface;
use Throwable;

/** Records Laravel AI pauses in the console's queryable index. */
final readonly class IngestToolApprovalRequests
{
    public function __construct(
        private ApprovalManager $approvals,
        private ResumableAgents $resumableAgents,
        private ConversationParticipants $participants,
        private ApprovalPresenter $presenter,
        private PendingApprovalStore $pendingApprovals,
        private ApprovalNotificationDispatcher $notifications,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    public function handle(ToolApprovalRequested $event): void
    {
        foreach ($event->pendingApprovals as $approval) {
            try {
                $this->ingest($event, $approval);
            } catch (ApprovalReceiptCollision $e) {
                $this->logger->critical('Verdict Console detected one receipt indexed by multiple pauses.', [
                    'tool_call_id' => $this->toolCallId($approval),
                    'exception' => $this->previousException($e),
                ]);
            } catch (ApprovalIngestionPersistenceFailed $e) {
                $this->logger->critical('Verdict Console could not durably record a paused approval.', [
                    'tool_call_id' => $this->toolCallId($approval),
                    'exception' => $this->previousException($e),
                ]);
            } catch (Throwable $e) {
                // A malformed sibling must not prevent later pending calls in the same Laravel AI
                // event from reaching their own durable row.
                $this->logger->error('Verdict Console could not ingest a paused approval.', [
                    'tool_call_id' => $this->toolCallId($approval),
                    'exception' => $e::class,
                ]);
            }
        }
    }

    private function ingest(ToolApprovalRequested $event, LaravelPendingApproval $approval): void
    {
        // This manager call is deliberately the sole receipt lookup. Its null answer is an
        // observation, not a receipt classification: absent, ambiguous, expired, and non-pending
        // all collapse here and no permitted public API separates them.
        $challenge = $this->approvals->challengeForToolCall($approval->id);
        [$resolverKey, $participantReference, $reason] = $this->resumability($event, $challenge);

        try {
            $outcome = $this->pendingApprovals->ingestWithOutcome(
                toolCallId: $approval->id,
                conversationId: $event->conversationId,
                participantReference: $participantReference,
                invocationId: $event->invocationId,
                receiptId: $challenge?->receiptId,
                resolverKey: $resolverKey,
                presentation: $this->presentation($approval, $challenge),
                resumability: $reason === null ? Resumability::Drivable : Resumability::Unresumable,
                unresumableReason: $reason,
            );
        } catch (UniqueConstraintViolationException $e) {
            throw ApprovalReceiptCollision::forToolCall($approval->id, $e);
        } catch (QueryException $e) {
            throw ApprovalIngestionPersistenceFailed::forToolCall($approval->id, $e);
        }

        if ($outcome->created) {
            // An event may be redelivered after its row was committed. Only the writer that created
            // that durable observation may begin notification work; the dispatcher then claims it
            // before sending so a second delivery path cannot duplicate it.
            $this->notifications->pending($outcome->pendingApproval);
        }

        if ($reason !== null && $outcome->created) {
            $this->events->dispatch(new ApprovalIngestionIncident($outcome->pendingApproval, $reason));
        }
    }

    /** @return array{0: string|null, 1: string|null, 2: UnresumableReason|null} */
    private function resumability(ToolApprovalRequested $event, ?ApprovalChallenge $challenge): array
    {
        if ($challenge === null) {
            // Challenge availability is deliberately first. It is the only observation we can make
            // for a receiptless, expired, ambiguous, or non-pending call, and we do not invoke
            // unrelated host resolver code after it fails. A known key is preserved only when the
            // later resolver check itself is what failed.
            return [null, null, UnresumableReason::ChallengeUnavailable];
        }

        try {
            $key = $this->resumableAgents->keyFor($event->agent);
        } catch (Throwable) {
            return [null, null, UnresumableReason::AgentUnresolvable];
        }

        try {
            $this->resumableAgents->resolve($key);
        } catch (Throwable) {
            // The key is a durable observation even when its factory is currently broken. Keeping
            // it gives the host's recovery protocol something concrete to repair or retire.
            return [$key, null, UnresumableReason::AgentUnresolvable];
        }

        if ($event->conversationId === null) {
            return [$key, null, UnresumableReason::ConversationAbsent];
        }

        if ($event->conversationUser === null) {
            return [$key, null, null];
        }

        try {
            $reference = $this->participants->referenceFor($event->conversationUser);
            $rebuilt = $this->participants->resolve($reference);

            if (Conversation::participantType($rebuilt) !== Conversation::participantType($event->conversationUser)
                || Conversation::participantKey($rebuilt) !== Conversation::participantKey($event->conversationUser)) {
                return [$key, null, UnresumableReason::ParticipantUnresolvable];
            }

            return [$key, $reference, null];
        } catch (Throwable) {
            // Laravel AI records and re-finds a paused turn by participant type/key as well as its
            // conversation id. This per-pause condition cannot be preflighted; treating a failed
            // host round trip as drivable only defers ApprovalMismatchException until after approval.
            return [$key, null, UnresumableReason::ParticipantUnresolvable];
        }
    }

    /** @return array<string, mixed>|null */
    private function presentation(LaravelPendingApproval $approval, ?ApprovalChallenge $challenge): ?array
    {
        try {
            $presentation = $this->presenter->present($approval, $challenge)->toArray();

            // The projection is **normalized** here, not merely validated. `ApprovalPresentation::details`
            // is host-owned `array<string, mixed>`, so a presenter can return perfectly well and still
            // hand back something JSON cannot represent — invalid UTF-8, a resource, an INF — and
            // `mixed` also admits `JsonSerializable`, which is host code that runs *during* encoding.
            //
            // Checking is not enough for that second kind. The store encodes again with
            // JSON_THROW_ON_ERROR after this guard has passed, and a second encode of the same array
            // re-invokes that host code: a stateful implementation can satisfy the check and fail the
            // write, whose JsonException then reaches the per-item catch-all, is filed as a malformed
            // sibling, and writes no row at all. The two encodes are not the same operation.
            //
            // Decoding the bytes that were just proven to encode yields JSON-native values only — no
            // objects, so nothing of the host's is left to run. The store's encode cannot invoke
            // anything and cannot fail for this input, and an unencodable presentation still degrades
            // exactly like a throwing one: row retained, drivability untouched, presentation null.
            $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> $normalized */
            $normalized = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            return $normalized;
        } catch (Throwable $e) {
            // Display projection is a host disclosure decision, not a drivability condition. The
            // row may still be safely resumable, so retain it with no presentation rather than
            // inventing an unresumable reason.
            $this->logger->warning('Verdict Console could not create an approval presentation.', [
                'tool_call_id' => $approval->id,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function toolCallId(mixed $approval): ?string
    {
        return $approval instanceof LaravelPendingApproval ? $approval->id : null;
    }

    /** @return class-string<Throwable> */
    private function previousException(Throwable $exception): string
    {
        return ($exception->getPrevious() ?? $exception)::class;
    }
}
