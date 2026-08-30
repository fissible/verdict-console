<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\VerdictConsole\Approvals\ApprovalChallengeReader;
use Fissible\VerdictConsole\Approvals\ApprovalSurfaceContract;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Http\VerdictConsoleRoutes;
use Fissible\VerdictConsole\View\Components\Approvals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The widget is a pure function of the console index and the live challenge, so the challenge
 * reader is faked per tool call: this suite proves markup, not Verdict.
 */
final class InboxChallenges implements ApprovalChallengeReader
{
    /** @var list<string> every tool call the widget asked Verdict about, in order */
    public array $reads = [];

    /** @param array<string, ApprovalChallenge|null> $byToolCall */
    public function __construct(private array $byToolCall = []) {}

    public function with(string $toolCallId, ?ApprovalChallenge $challenge): self
    {
        $this->byToolCall[$toolCallId] = $challenge;

        return $this;
    }

    public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
    {
        $this->reads[] = $toolCallId;

        return $this->byToolCall[$toolCallId] ?? null;
    }
}

final readonly class InboxConversationScope implements ApprovalScope
{
    public function __construct(private string $conversationId) {}

    public function apply(Builder $query): Builder
    {
        return $query->where('conversation_id', $this->conversationId);
    }
}

function inboxChallenge(string $toolCallId, ?ProposalProvenance $provenance = null, string $expiresAt = '2030-01-02T03:04:05+00:00'): ApprovalChallenge
{
    return new ApprovalChallenge(
        receiptId: 'receipt-'.$toolCallId,
        toolCallId: $toolCallId,
        capability: 'orders.cancel',
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable($expiresAt),
        provenance: $provenance,
    );
}

/** @return array<string, mixed> */
function inboxPresentation(string $tool = 'CancelOrderTool'): array
{
    return [
        'tool' => $tool,
        'capability' => 'orders.cancel',
        'reason' => 'Cancelling an order needs confirmation.',
        'arguments_fingerprint' => 'sha256:'.str_repeat('a', 64),
        'details' => ['order' => '#1001'],
    ];
}

function renderInbox(): string
{
    return (string) test()->blade('<x-verdict-console::approvals />');
}

/**
 * Split the rendered widget into rows keyed by approval id, so assertions can say "this row" and
 * "this row only". The `data-approval` attribute on the row's wrapping element is the widget's
 * stable contract for tests and host CSS alike; which element wraps a row, and what it nests, is
 * the view's choice — hence a DOM walk rather than a regex that would stop at the first nested
 * closing tag.
 *
 * @return array<string, string>
 */
function inboxRows(string $html): array
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $rows = [];

    foreach ((new DOMXPath($document))->query('//*[@data-approval]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $rows[$node->getAttribute('data-approval')] = (string) $document->saveHTML($node);
        }
    }

    return $rows;
}

function rowAttribute(string $row, string $attribute): ?string
{
    return preg_match('/^<\w+\b[^>]*\b'.preg_quote($attribute, '/').'="([^"]*)"/', $row, $m) === 1 ? $m[1] : null;
}

/**
 * The rendered widget with every per-run value replaced by a stable placeholder, so the complete
 * markup of the three-state matrix can be held as a snapshot: row ids become {approval-N} and the
 * mounted route URLs follow them.
 *
 * @param  list<string>  $ids  approval ids in the order they should be numbered
 */
function normalizedInbox(string $html, array $ids): string
{
    foreach ($ids as $n => $id) {
        $html = str_replace($id, '{approval-'.($n + 1).'}', $html);
    }

    // The CSRF token value is per session; the field's presence is what the snapshot pins.
    $html = preg_replace('/(name="_token"\s+value=")[^"]*(")/', '${1}{token}${2}', $html) ?? $html;

    return trim(preg_replace('/^[ \t]+|[ \t]+$/m', '', $html) ?? $html);
}

/** @return list<ApprovalVerb> */
function renderedVerbs(string $row): array
{
    preg_match_all('/<form\b[^>]*\bdata-verb="([^"]+)"/', $row, $matches);

    return array_map(fn (string $verb): ApprovalVerb => ApprovalVerb::from($verb), $matches[1]);
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();

    $this->challenges = new InboxChallenges;
    app()->instance(ApprovalChallengeReader::class, $this->challenges);
    $this->store = new PendingApprovalStore;
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
    VerdictConsoleRoutes::$registersRoutes = true;
});

/** The three rows the issue names, and only those, each told apart by its state attribute. */
it('renders drivable, not-console-actionable, and expired-or-already-decided rows distinctly', function (): void {
    VerdictConsoleRoutes::register();
    $drivable = $this->store->ingest('call_drivable', conversationId: 'c1', receiptId: 'receipt-call_drivable', presentation: inboxPresentation(), resumability: Resumability::Drivable);
    $unresumable = $this->store->ingest('call_unresumable', conversationId: 'c2', receiptId: 'receipt-call_unresumable', presentation: inboxPresentation('RefundTool'), resumability: Resumability::Unresumable, unresumableReason: UnresumableReason::AgentUnresolvable);
    $lapsed = $this->store->ingest('call_lapsed', conversationId: 'c3', receiptId: 'receipt-call_lapsed', presentation: inboxPresentation('CloseAccountTool'), resumability: Resumability::Drivable);
    $this->challenges->with('call_drivable', inboxChallenge('call_drivable'))->with('call_unresumable', inboxChallenge('call_unresumable'))->with('call_lapsed', null);

    $rows = inboxRows(renderInbox());

    expect(array_keys($rows))->toEqualCanonicalizing([$drivable->id, $unresumable->id, $lapsed->id]);

    expect(rowAttribute($rows[$drivable->id], 'data-state'))->toBe('pending')
        ->and(renderedVerbs($rows[$drivable->id]))->toEqualCanonicalizing([ApprovalVerb::Approve, ApprovalVerb::Reject])
        ->and($rows[$drivable->id])->toContain('CancelOrderTool')
        ->and($rows[$drivable->id])->toContain('orders.cancel');

    // Verdict still holds a live receipt here, but this console cannot drive the run: the row must
    // say so, and must offer nothing that looks like a decision.
    expect(rowAttribute($rows[$unresumable->id], 'data-state'))->toBe('not_console_actionable')
        ->and(rowAttribute($rows[$unresumable->id], 'data-unresumable-reason'))->toBe('agent_unresolvable')
        ->and(renderedVerbs($rows[$unresumable->id]))->toBe([])
        ->and($rows[$unresumable->id])->not->toContain('<form')
        ->and($rows[$unresumable->id])->not->toContain('<button')
        ->and($rows[$unresumable->id])->not->toContain('data-verb');

    // A null challenge collapses expired with already-decided (ADR 0001 §3); the copy says both and
    // the only verb is the non-authorizing close — a real POST form, to the named route, with a token.
    expect(rowAttribute($rows[$lapsed->id], 'data-state'))->toBe('expired_or_already_decided')
        ->and($rows[$lapsed->id])->toContain('expired or already decided')
        ->and(renderedVerbs($rows[$lapsed->id]))->toBe([ApprovalVerb::Close])
        ->and($rows[$lapsed->id])->toContain('action="'.route('verdict-console.approvals.close', $lapsed->id).'"')
        ->and($rows[$lapsed->id])->toContain('method="post"')
        ->and($rows[$lapsed->id])->toContain('_token')
        ->and($rows[$lapsed->id])->not->toContain('data-verb="approve"');
});

/**
 * The issue asks for snapshot coverage, and this is where it earns its keep: the complete markup
 * of the three-state matrix, normalized, so an unintended change to any row's shape — a control
 * appearing, a state label drifting, a provenance line vanishing — fails visibly rather than
 * slipping past fragment assertions. Ids, route URLs, and the token are placeholders.
 */
it('matches the snapshot of the three-state matrix', function (): void {
    VerdictConsoleRoutes::register();
    // Distinct ingestion instants: the inbox lists newest first, and three rows ingested within one
    // second would otherwise tie and fall back to their random ids.
    Carbon::setTestNow('2026-08-30 10:00:00');
    $drivable = $this->store->ingest('call_drivable', conversationId: 'c1', receiptId: 'receipt-call_drivable', presentation: inboxPresentation(), resumability: Resumability::Drivable);
    Carbon::setTestNow('2026-08-30 10:00:01');
    $unresumable = $this->store->ingest('call_unresumable', conversationId: 'c2', receiptId: 'receipt-call_unresumable', presentation: inboxPresentation('RefundTool'), resumability: Resumability::Unresumable, unresumableReason: UnresumableReason::AgentUnresolvable);
    Carbon::setTestNow('2026-08-30 10:00:02');
    $lapsed = $this->store->ingest('call_lapsed', conversationId: 'c3', receiptId: 'receipt-call_lapsed', presentation: inboxPresentation('CloseAccountTool'), resumability: Resumability::Drivable);
    Carbon::setTestNow();
    $this->challenges
        ->with('call_drivable', inboxChallenge('call_drivable', ProposalProvenance::declared([
            new UpstreamSource(Source::external('search'), Trust::Untrusted, DataClass::Public, ContextChannel::RetrievedDocument),
        ], undescribedSourceCount: 1, withheldSourceCount: 0)))
        ->with('call_unresumable', inboxChallenge('call_unresumable', ProposalProvenance::unknown()))
        ->with('call_lapsed', null);

    expect(normalizedInbox(renderInbox(), [$drivable->id, $unresumable->id, $lapsed->id]))->toMatchSnapshot();
});

/**
 * ADR 0001's verb invariant, pinned the way every surface must pin it: the verbs the markup offers
 * are compared to VC-41's resolver, so presentation code cannot grow an approve button of its own.
 */
it('renders exactly the verb set the surface contract resolves for every row', function (): void {
    VerdictConsoleRoutes::register();
    $rows = [
        'call_drivable' => $this->store->ingest('call_drivable', conversationId: 'c1', receiptId: 'receipt-call_drivable', resumability: Resumability::Drivable),
        'call_unresumable' => $this->store->ingest('call_unresumable', conversationId: 'c2', receiptId: 'receipt-call_unresumable', resumability: Resumability::Unresumable, unresumableReason: UnresumableReason::ChallengeUnavailable),
        'call_lapsed' => $this->store->ingest('call_lapsed', conversationId: 'c3', receiptId: 'receipt-call_lapsed', resumability: Resumability::Drivable),
    ];
    $challenges = ['call_drivable' => inboxChallenge('call_drivable'), 'call_unresumable' => null, 'call_lapsed' => null];

    foreach ($challenges as $toolCallId => $challenge) {
        $this->challenges->with($toolCallId, $challenge);
    }

    $rendered = inboxRows(renderInbox());
    $contract = app(ApprovalSurfaceContract::class);

    foreach ($rows as $toolCallId => $approval) {
        $contract->assertRendered(renderedVerbs($rendered[$approval->id]), $approval, $challenges[$toolCallId]);
    }

    expect(count($rendered))->toBe(3);
});

/** ADR 0001 §5: four disclosure states plus the pre-capture era, never collapsed and never silent. */
it('renders every provenance disclosure state distinctly', function (?ProposalProvenance $provenance, string $state, array $fragments): void {
    $approval = $this->store->ingest('call_1', conversationId: 'c1', receiptId: 'receipt-call_1', resumability: Resumability::Drivable);
    $this->challenges->with('call_1', inboxChallenge('call_1', $provenance));

    $row = inboxRows(renderInbox())[$approval->id];

    expect($row)->toContain('data-provenance="'.$state.'"');

    foreach ($fragments as $fragment) {
        expect($row)->toContain($fragment);
    }
})->with([
    'declared with an untrusted external source' => [
        ProposalProvenance::declared([
            new UpstreamSource(Source::external('search'), Trust::Untrusted, DataClass::Public, ContextChannel::RetrievedDocument),
            new UpstreamSource(Source::user('customer'), Trust::Trusted, DataClass::PII, ContextChannel::UserInput),
        ]),
        'declared',
        ['data-source-warning="true"', 'data-source-warning="false"', 'external', 'search', 'untrusted', 'retrieved_document'],
    ],
    'declared with withheld and undescribed sources' => [
        ProposalProvenance::declared([], undescribedSourceCount: 2, withheldSourceCount: 1),
        'declared',
        ['2 upstream sources undescribed', '1 upstream source withheld by release policy'],
    ],
    'unknown' => [ProposalProvenance::unknown(), 'unknown', ['provenance unknown — no derivation was declared']],
    'unreleased' => [ProposalProvenance::unreleased(), 'unreleased', ['the application has not configured provenance release to approvers']],
    'issued before capture' => [null, 'issued_before_provenance_capture', ['issued before provenance capture']],
]);

/** The reason is labelled as what it is, and expiry is the challenge's, never the stored presentation's. */
it('labels the gating reason and shows live expiry from the challenge', function (): void {
    $approval = $this->store->ingest('call_1', conversationId: 'c1', receiptId: 'receipt-call_1', presentation: [...inboxPresentation(), 'expires_at' => '1999-01-01T00:00:00+00:00'], resumability: Resumability::Drivable);
    $this->challenges->with('call_1', inboxChallenge('call_1', expiresAt: '2030-01-02T03:04:05+00:00'));

    $row = inboxRows(renderInbox())[$approval->id];

    expect($row)->toContain('Why this capability is gated')
        ->and($row)->toContain('Cancelling an order needs confirmation.')
        ->and($row)->toContain('datetime="2030-01-02T03:04:05+00:00"')
        ->and($row)->not->toContain('1999-01-01');
});

/** ADR 0001 §5's last bullet: the affordances that manufacture fatigue are not shipped. */
it('ships no default-selected verb, no autofocus, and no bulk control', function (): void {
    VerdictConsoleRoutes::register();
    $this->store->ingest('call_1', conversationId: 'c1', receiptId: 'receipt-call_1', resumability: Resumability::Drivable);
    $this->store->ingest('call_2', conversationId: 'c2', receiptId: 'receipt-call_2', resumability: Resumability::Drivable);
    $this->challenges->with('call_1', inboxChallenge('call_1'))->with('call_2', inboxChallenge('call_2'));

    $html = renderInbox();

    expect($html)->not->toContain('autofocus')
        ->and($html)->not->toContain('type="checkbox"')
        ->and($html)->not->toContain('<select')
        ->and(substr_count($html, 'data-verb="approve"'))->toBe(2, 'One approve control per row; nothing acts on more than one row.');
});

it('renders nothing for a row outside the host scope, not even a disabled one', function (): void {
    $visible = $this->store->ingest('call_visible', conversationId: 'tenant-a', receiptId: 'receipt-call_visible', resumability: Resumability::Drivable);
    $hidden = $this->store->ingest('call_hidden', conversationId: 'tenant-b', receiptId: 'receipt-call_hidden', resumability: Resumability::Drivable);
    $this->challenges->with('call_visible', inboxChallenge('call_visible'))->with('call_hidden', inboxChallenge('call_hidden'));
    app()->instance(ApprovalScope::class, new InboxConversationScope('tenant-a'));

    $html = renderInbox();

    expect(array_keys(inboxRows($html)))->toBe([$visible->id])
        ->and($html)->not->toContain($hidden->id)
        ->and($html)->not->toContain('call_hidden')
        // Scope is applied to the query, not to the output: a hidden row is never read from Verdict.
        ->and($this->challenges->reads)->toBe(['call_visible']);
});

it('says when nothing is waiting instead of rendering an empty list', function (): void {
    $html = renderInbox();

    expect($html)->toContain('data-verdict-console="approvals"')
        ->and($html)->toContain('No approvals are waiting.')
        ->and(inboxRows($html))->toBe([]);
});

/**
 * Routes mount at boot by default, and a host may opt out (`VerdictConsoleRoutes::ignoreRoutes()`
 * or the config switch). For a host that did, the widget still renders every row honestly but
 * posts nowhere: no form whose action would be a guess, and a visible statement of why the
 * controls are absent.
 */
it('renders rows without forms and says so for a host that opted out of the console routes', function (): void {
    $approval = $this->store->ingest('call_1', conversationId: 'c1', receiptId: 'receipt-call_1', resumability: Resumability::Drivable);
    $this->challenges->with('call_1', inboxChallenge('call_1'));

    expect(Route::has('verdict-console.approvals.approve'))->toBeTrue('The routes mount at boot by default.');

    // The opted-out host: it ignored the routes, so nothing of the console's is in the router.
    VerdictConsoleRoutes::ignoreRoutes();
    Route::setRoutes(new RouteCollection);

    $html = renderInbox();
    $row = inboxRows($html)[$approval->id];

    expect($html)->toContain('data-routes="unmounted"')
        ->and($row)->not->toContain('<form')
        ->and($row)->toContain('console routes are not registered')
        ->and(rowAttribute($row, 'data-state'))->toBe('pending');

    VerdictConsoleRoutes::register();

    $mounted = inboxRows(renderInbox())[$approval->id];

    expect(renderInbox())->toContain('data-routes="mounted"')
        ->and($mounted)->toContain('action="'.route('verdict-console.approvals.approve', $approval->id).'"')
        ->and($mounted)->toContain('action="'.route('verdict-console.approvals.reject', $approval->id).'"')
        ->and($mounted)->toContain('method="post"')
        ->and($mounted)->toContain('_token');
});

/** The persisted presentation is shown as a summary; nothing here reaches for raw arguments. */
it('shows the presentation summary and the host details, never raw arguments', function (): void {
    $approval = $this->store->ingest('call_1', conversationId: 'c1', receiptId: 'receipt-call_1', presentation: inboxPresentation(), resumability: Resumability::Drivable);
    $this->challenges->with('call_1', inboxChallenge('call_1'));

    $row = inboxRows(renderInbox())[$approval->id];

    expect($row)->toContain('CancelOrderTool')
        ->and($row)->toContain('sha256:'.str_repeat('a', 64))
        ->and($row)->toContain('#1001');
});

it('renders a row whose presentation could not be captured without failing the whole widget', function (): void {
    $approval = $this->store->ingest('call_1', conversationId: 'c1', receiptId: 'receipt-call_1', presentation: null, resumability: Resumability::Drivable);
    $this->challenges->with('call_1', inboxChallenge('call_1'));

    $row = inboxRows(renderInbox())[$approval->id];

    expect(rowAttribute($row, 'data-state'))->toBe('pending')
        ->and($row)->toContain('orders.cancel');
});

it('is a class-based component registered under the verdict-console namespace', function (): void {
    expect(class_exists(Approvals::class))->toBeTrue()
        ->and(PendingApproval::query()->count())->toBe(0)
        ->and(renderInbox())->toContain('data-verdict-console="approvals"');
});

/**
 * The chat thread draws its interrupt through this widget, scoped to one conversation. Rows of
 * other conversations are neither rendered nor read from Verdict, and the empty state is silent —
 * an empty interrupt is no interrupt.
 */
it('renders only one conversations rows when given a conversation, and nothing when it has none', function (): void {
    $mine = $this->store->ingest('call_mine', conversationId: 'conversation-a', receiptId: 'receipt-call_mine', resumability: Resumability::Drivable);
    $this->store->ingest('call_other', conversationId: 'conversation-b', receiptId: 'receipt-call_other', resumability: Resumability::Drivable);
    $this->challenges->with('call_mine', inboxChallenge('call_mine'))->with('call_other', inboxChallenge('call_other'));

    $html = (string) $this->blade('<x-verdict-console::approvals :conversation="$conversation" />', ['conversation' => 'conversation-a']);

    expect(array_keys(inboxRows($html)))->toBe([$mine->id])
        ->and($this->challenges->reads)->toBe(['call_mine'])
        ->and($html)->toContain('data-conversation="conversation-a"');

    $empty = (string) $this->blade('<x-verdict-console::approvals :conversation="$conversation" />', ['conversation' => 'conversation-none']);

    expect(inboxRows($empty))->toBe([])
        ->and($empty)->not->toContain('No approvals are waiting.');
});
