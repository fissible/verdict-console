<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalScopeMatch;
use Fissible\VerdictConsole\Approvals\ApprovalContextScope;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * VC-69: the recommended scope, keyed on the captured `approval_context` with the same typed-exact
 * containment as Verdict's ADR 0031 §3 — so what the console shows a person stays a subset of what
 * Verdict would let them decide. The published ApprovalScope contract is untouched: this is one
 * more implementation of it, not a narrowing.
 */
final readonly class ContextHostScope implements ApprovalScope
{
    public function __construct(private string $conversationId) {}

    public function apply(Builder $query): Builder
    {
        return $query->where('conversation_id', $this->conversationId);
    }
}

/** @param array<string, string|int>|null $context */
function contextRow(PendingApprovalStore $store, string $toolCallId, ?array $context): PendingApproval
{
    return $store->ingest(
        toolCallId: $toolCallId,
        conversationId: 'conv-'.$toolCallId,
        receiptId: 'receipt-'.$toolCallId,
        approvalContext: $context,
    );
}

/** @return list<string> the visible rows' tool call ids under the currently bound scope */
function visibleToolCalls(): array
{
    return array_values(array_map(
        static fn (PendingApproval $row): string => $row->tool_call_id,
        app(PendingApprovalStore::class)->visible(),
    ));
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();

    $this->store = new PendingApprovalStore;
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
});

/** ADR 0031 §3's mechanical rejection, mirrored: an unscoped global visibility is not expressible here either. */
it('refuses an empty scope at construction', function (): void {
    expect(fn (): ApprovalContextScope => new ApprovalContextScope([]))
        ->toThrow(InvalidArgumentException::class);
});

/** No coercion in either direction: integer 7 and string '7' are different identifiers. */
it('matches typed-exactly in both directions', function (): void {
    contextRow($this->store, 'call_int', ['workspace' => 7]);
    contextRow($this->store, 'call_string', ['workspace' => '7']);

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['workspace' => 7]));

    expect(visibleToolCalls())->toBe(['call_int']);

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['workspace' => '7']));

    expect(visibleToolCalls())->toBe(['call_string']);
});

it('requires every scope pair and ignores extra row keys', function (): void {
    $full = contextRow($this->store, 'call_full', ['tenant' => 'acme', 'workspace' => 7, 'extra' => 'noise']);
    contextRow($this->store, 'call_partial', ['tenant' => 'acme']);
    contextRow($this->store, 'call_other', ['tenant' => 'beta', 'workspace' => 7]);

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['tenant' => 'acme', 'workspace' => 7]));

    expect(visibleToolCalls())->toBe(['call_full'])
        ->and(app(PendingApprovalStore::class)->isVisible($full))->toBeTrue();
});

/** A null or empty captured context is never in scope: no identifiers, no visibility through this scope. */
it('never scopes in a row whose context is null or empty', function (): void {
    contextRow($this->store, 'call_null', null);
    contextRow($this->store, 'call_empty', []);
    $matching = contextRow($this->store, 'call_match', ['tenant' => 'acme']);
    $nullRow = PendingApproval::query()->where('tool_call_id', 'call_null')->sole();

    $foreign = contextRow($this->store, 'call_foreign', ['tenant' => 'beta']);
    $mistyped = contextRow($this->store, 'call_mistyped', ['tenant' => 'acme', 'workspace' => '7']);

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['tenant' => 'acme', 'workspace' => 7]));
    $mismatch = contextRow($this->store, 'call_ws', ['tenant' => 'acme', 'workspace' => 7]);

    // Every scoped read refuses the same rows: a scope that filtered the list but leaked a
    // wrong-tenant or coerced row through the single-row reads would be an authorization gap.
    expect(app(PendingApprovalStore::class)->findVisible((string) $nullRow->getKey()))->toBeNull()
        ->and(app(PendingApprovalStore::class)->isVisible($nullRow))->toBeFalse()
        ->and(app(PendingApprovalStore::class)->findVisible((string) $foreign->getKey()))->toBeNull()
        ->and(app(PendingApprovalStore::class)->isVisible($foreign))->toBeFalse()
        ->and(app(PendingApprovalStore::class)->findVisible((string) $mistyped->getKey()))->toBeNull()
        ->and(app(PendingApprovalStore::class)->isVisible($mistyped))->toBeFalse()
        ->and(app(PendingApprovalStore::class)->findVisible((string) $mismatch->getKey()))->not->toBeNull()
        ->and(app(PendingApprovalStore::class)->isVisible($mismatch))->toBeTrue();

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['tenant' => 'acme']));

    expect(visibleToolCalls())->toEqualCanonicalizing(['call_match', 'call_mistyped', 'call_ws'])
        ->and(app(PendingApprovalStore::class)->findVisible((string) $matching->getKey()))->not->toBeNull();
});

/** A scope that matches nothing shows nothing — never everything. */
it('shows an empty list when no row carries the scoped identifiers', function (): void {
    contextRow($this->store, 'call_a', ['tenant' => 'acme']);
    contextRow($this->store, 'call_b', ['tenant' => 'beta']);
    contextRow($this->store, 'call_c', null);

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['tenant' => 'gamma']));

    expect(visibleToolCalls())->toBe([]);
});

/**
 * The published contract is not narrowed: an arbitrary host scope keyed on anything else keeps
 * working unchanged beside the recommended one — receiving the query, returning it constrained.
 */
it('leaves an arbitrary host scope working unmodified', function (): void {
    contextRow($this->store, 'call_a', ['tenant' => 'acme']);
    contextRow($this->store, 'call_b', ['tenant' => 'acme']);

    app()->instance(ApprovalScope::class, new ContextHostScope('conv-call_b'));

    expect(visibleToolCalls())->toBe(['call_b']);
});

/**
 * The subset guarantee is only as good as rule parity, so the console's matching is pinned case
 * for case against Verdict's own `ApprovalScopeMatch` — the premise (Verdict's answer) and the
 * behavior (what the bound scope makes visible) must both equal the expectation, or the two sides
 * have drifted.
 */
it('agrees with Verdict containment case for case', function (?array $context, array $scope, bool $expected): void {
    expect(ApprovalScopeMatch::matches($context, $scope))->toBe($expected, 'Premise: this is Verdict\'s own answer.');

    $row = contextRow($this->store, 'call_case', $context);
    app()->instance(ApprovalScope::class, new ApprovalContextScope($scope));

    expect(app(PendingApprovalStore::class)->isVisible($row))->toBe($expected);
})->with([
    'exact single pair' => [['t' => 'a'], ['t' => 'a'], true],
    'scope is a subset of the row' => [['t' => 'a', 'w' => 7], ['t' => 'a'], true],
    'integer row, string scope' => [['w' => 7], ['w' => '7'], false],
    'string row, integer scope' => [['w' => '7'], ['w' => 7], false],
    'missing scope key on the row' => [['t' => 'a'], ['t' => 'a', 'w' => 7], false],
    'null context' => [null, ['t' => 'a'], false],
    'empty context' => [[], ['t' => 'a'], false],
    'integer zero exact' => [['n' => 0], ['n' => 0], true],
    'string zero against integer zero' => [['n' => '0'], ['n' => 0], false],
    'empty-string value exact' => [['t' => ''], ['t' => ''], true],
]);
