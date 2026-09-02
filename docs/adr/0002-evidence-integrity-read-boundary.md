# ADR 0002: Evidence integrity is its own read boundary, and its results are dated claims

Status: Proposed

## Related issues

- [#108](https://github.com/fissible/verdict-console/issues/108) is the work this settles.
- [#104](https://github.com/fissible/verdict-console/issues/104) shipped the `Chained` recording
  state this boundary hangs from; its copy — "A chained sink (…) is configured; decisions are not
  readable from this table." — is **unchanged** by this ADR. Integrity renders adjacently, from
  its own source.
- Verdict ADR 0008 — evidence privacy; nothing here widens what a surface may show.
- attest owns chain traversal, signature verification, anchors, and their outcome vocabulary.
- [verdict#468](https://github.com/fissible/verdict/issues/468) /
  [verdict#469](https://github.com/fissible/verdict/issues/469) — this cluster's earlier upstream
  handoffs; two more are filed from this ADR (§7, §8).

Grounded against: fissible/verdict v0.15.0 (`AttestEvidenceRecorder::recordGap`,
`VerifyEvidenceCommand`), fissible/attest-laravel v1.0.1 (`Services\VerifyChain` — marked
`@internal`; migrations: envelopes, anchor claims, import markers, **no verification runs**),
fissible/attest v1.3.0 (`Verification\StaticVerifier::verifyChain`), this package at
post-v0.9.0 main (`Chained` state, `ChainWriteFailed → RecordAnomalyIncident` listener,
`VerdictReadBoundaryTest` table-seam pins).

## Context

An attest-configured host chains its decisions into signed envelopes, and the evidence surfaces
now say so honestly — and can say nothing more. Verification exists twice, both outside every
console surface: `verdict:evidence:verify` (Verdict's configuration-aware delegation to
`attest:verify`, whose exit vocabulary attest owns) and attest-laravel's programmatic
`VerifyChain` — which is `@internal`, so no released package may depend on it today.

Nothing anywhere records that a verification *happened*: attest-laravel persists no run history.
"Is the trail intact?" is answerable only by whoever last ran a command and remembers.

Failed chain writes leave two traces: Verdict's `ChainWriteFailed` event, already ledgered into
incidents, and `chain_gap` marker rows in the evidence table — **best-effort** marks
(`recordGap()` swallows its own write failures), so the marks that exist undercount the failures
that occurred. And the console's evidence surfaces deliberately do not read the evidence table for
a chained sink; nothing below may quietly reopen that seam.

## Decision

### 1. A separate boundary with a decidable contract

Integrity is read through a console-owned, host-replaceable contract beside — never inside — the
evidence state boundary:

```php
interface EvidenceIntegrity
{
    /** One view per chain the host names (§3), in the named order. @return list<ChainIntegrityView> */
    public function chains(): array;
}

final readonly class ChainIntegrityView
{
    public string $chainId;
    public ChainIntegrityState $state;       // §2 — derived from lastCompleted, never lastAttempt
    public ?RecordedVerification $lastCompleted;   // the standing claim; null before any completes
    public ?RecordedVerification $lastAttempt;     // newest attempt of any outcome, errored included
    public ?GapTrace $gaps;                  // null when the provider cannot read gap marks (§6)
}

final readonly class RecordedVerification
{
    public string $outcome;                  // 'verified' | 'failed' | 'errored' (§4)
    // 'errored' rows never become lastCompleted: the standing state survives a broken run.
    public DateTimeImmutable $ranAt;
    public string $ranBy;                    // opaque actor/process key
    public int $fromSeq;
    public ?int $toSeqRequested;
    public ?int $verifiedThroughSeq;         // the claim's true extent
    public ?int $brokenAtSeq;                // failed only
    public ?string $attestOutcome;           // attest's own word, carried verbatim
    public string $policyFingerprint;        // hash of the verification inputs (§4)
    public string $source;                   // 'automated' | 'recorded' — who produced the values (§4)
    public ?string $outputDigest;            // sha256 of the run's output artifact when supplied (§4)
    /** @var array<string, string> every executed component: recorder, attest-laravel, attest, verdict */
    public array $verifierVersions;          // immutable map, stored as written
}

final readonly class GapTrace
{
    public int $persistedMarks;              // best-effort by design — a floor, not a count
    public ?DateTimeImmutable $latestMarkAt;
}
```

The core binds a `NullEvidenceIntegrity` default that derives `NotApplicable` / `Unnameable` /
`Unverified` from configuration alone and never reads a table.

### 2. Five states, and the copy each one is allowed

- `NotApplicable` — no chained sink is configured. Renders nothing: #104's states already speak.
- `Unnameable` — a chained sink is configured through a **resolver** and the host has named no
  chains (§3). Copy: "Chained through a resolver; no chains are named for integrity reporting."
- `Unverified` — a named chain with no recorded verification. Copy: "Not yet verified." Never a
  warning color, never suspicion: a fresh host that has never run verification is healthy.
- `Verified` — copy: "Verified through sequence {N} at {instant} by {ranBy}." — with ", as
  recorded by {ranBy}" replacing the attribution when the claim's source is `recorded` (§4).
  Never "the chain is
  verified": the claim covers the range as of the instant, says nothing about later envelopes, and
  verification of recorded entries cannot show that every event was recorded.
- `Failed` — copy: "Verification failed at sequence {N} ({attest outcome}) at {instant}." The
  surface reports *verification failed*; tampering is one cause among key rotation,
  misconfiguration, and truncation, and the copy must not collapse them.

An `errored` **run** (§4) is not a sixth state: the chain's state derives from
`lastCompleted` alone (or `Unverified` when none exists), and a newer errored `lastAttempt`
renders beside it — "Last verification attempt errored at {instant}." — without disturbing the
standing claim.

`NotApplicable` derives from the **effective** sink — the same writer-precedence truth the
evidence posture reads — never from the mere presence of chain configuration keys: chain settings
beside a non-attest effective writer are inert, and the surface says nothing.

### 3. Chains are named or unnameable — never derived

The boundary reports on chains the host names: the fixed chain Verdict's configuration declares,
or an explicit `verdict-console.integrity.chains` list. A tenant chain resolver cannot be
enumerated from a process-wide surface — Verdict's own CLI refuses exactly this — so
resolver-backed deployments name their chains or render `Unnameable`. The console never derives
chain ids from data.

A configuration Verdict itself rejects — a fixed chain and a resolver both set — is not a topology
to report on: the provider fails closed to `Unnameable` with its own copy variant, "Chain
configuration is invalid; integrity cannot be reported.", and the misconfiguration is the
Doctor's to flag (a finding shipped with the core slice, exact code and fix text pinned there).

### 4. Verification results are dated claims, recorded durably by the console

A verification result is a claim someone made at an instant, about a range, under a policy. The
boundary reads a durable, console-owned record — **one row per chain** (unique on chain id)
holding two record groups: the last **completed** verification (verified or failed), replaced only
by the next completed run, and the last **attempt** of any outcome, replaced by every run. An
errored attempt therefore never erases the standing claim. Run history beyond these is the host's
to build; the console's question is "what is currently known, and since when".

The record is written by whatever performs verification (§7's command, a schedule, or a host's own
job), including on failure and on **error**: an attempt that threw, or whose result could not be
interpreted, records `errored` with the exception class only — never a message, which commonly
carries paths and configuration. `policyFingerprint` hashes the inputs that determine the claim —
trusted keys, anchor requirements, range — so two runs under different policies cannot masquerade
as the same claim; `verifierVersions` records what did the judging.

The recording command is a **claim intake, not a verifier**, and its trust contract is pinned:
`ranAt` and `ranBy` are derived at execution — the clock and the executing process or operator
identity — never accepted as inputs, so a record cannot be back-dated or attributed to someone who
did not run it. Every record carries its `source`: `recorded` for the command's typed inputs,
`automated` for a bridge that both verified and recorded in one act. The command's authority
boundary is process access — it is operator-plane by nature, like every artisan command — and what
it produces is exactly what the surfaces say it is: a dated, attributed, operator-recorded claim.
Copy renders the distinction — a `recorded` claim reads "…as recorded by {ranBy}", never as
independently verified. The command accepts an optional `--output-digest` (sha256 of the run's
output artifact), persisted verbatim as `outputDigest` for after-the-fact audit; it has **no**
effect on state or copy — a recorded claim reads as a recorded claim with or without it, because
promoting digest-bearing records to stronger wording would let a pasted digest buy credibility
the console never checked.

Surfaces never verify on render: a render-time chain walk is unbounded work, and it would re-mint
the claim's timestamp on every page view. Triggering verification from a console surface is out of
scope, on the grounds the configuration inspection records for writes.

### 5. Chain-and-range only — there are no per-record claims

A verification outcome belongs to a chain over a range. Envelope↔record correlation is by
correlation id inside signed payloads, not by evidence-row id, and a range outcome does not
individually attest a row. Per-row "verified" badges are refused; the one honest per-row statement
is its chain's status, rendered once.

### 6. Gap marks ride the view, best-effort, and only a provider that may read them supplies them

`GapTrace` reports **persisted gap marks** — a floor, not a count, because `recordGap()` is
deliberately best-effort. The copy is its own: "{n} chain-write gap marks (latest {instant})" —
never merged into a verification outcome; a gap is a write-availability wound, not a tamper
finding. `ChainWriteFailed → RecordAnomalyIncident` is unchanged and remains the stream that
catches what never left a mark.

The core does not read gap marks: the evidence surfaces' refusal to read the table behind a
chained sink is a pinned seam this ADR does not reopen. The bridge (§7), which already owns
attest-adjacent storage knowledge, reads the `chain_gap` schema; the null default reports
`gaps: null`, rendered as no gap information rather than zero gaps. Should Verdict publish a gap
read contract, the bridge thins (§8).

### 7. Core ships whole and ungated; the bridge is gated on an upstream seam

The console core takes no attest dependency and ships everything but automation: the contract and
DTOs, the five states and their copy, the null default, the two-group verification-record store,
the surface rendering, the invalid-topology Doctor finding — and a
`verdict-console:record-verification` command taking explicit, typed inputs (chain, outcome,
range, attest outcome, policy fingerprint, versions, and the optional `--output-digest` of §4). The record is a dated claim by design, and
`ranBy` names its maker: a host that runs `verdict:evidence:verify` on a schedule can record what
it saw the same day the core lands, with no bridge involved.

The `verdict-console-attest` bridge — the attest-backed `EvidenceIntegrity` with automated
verification-plus-recording and the gap trace — is **gated on attest-laravel publishing a stable
programmatic verification seam**: both `VerifyChain` and the `attest:verify` command are
`@internal` today, and neither exposes a machine-readable output contract the bridge could pin
(`verifiedThroughSeq`, `brokenAtSeq`, and the outcome would be screen-scraping). An `@internal`
seam is a contract with nobody; the bridge waits rather than binding to one.

### 8. Upstream handoffs filed from this ADR

- attest-laravel (**blocking for the bridge**): publish a stable programmatic verification seam —
  structured result (outcome, verified-through, broken-at), stable outcome vocabulary.
- attest-laravel (recorded as alternative, not requested): upstream verification-run storage would
  let the console's record become a read of attest's own history. The console-owned record stands
  regardless — the console's claim provenance must not depend on an optional upstream table.
- verdict: a read contract for `chain_gap` marks, so the bridge (when built) never parses the
  fallback schema.

## Consequences

- Implementation tickets filed from this ADR: core slice (contract, states + exact copy,
  two-group record store, recording command, Doctor finding, rendering — M/L, ungated);
  `verdict-console-attest` (L; own repository, release tooling from verdict-console main,
  first-release path; **blocked on §8's attest-laravel seam**); the three upstream filings of §8.
  Adapter surfaces follow the core slice.
- The design of record gains an integrity section at implementation time; #104's copy and the
  incident ledger are unchanged.
- Copy discipline is load-bearing and the strings in §2/§6 are the pinned copy — tests assert
  them verbatim.

## Alternatives rejected

### Verify on render
Unbounded work per view, and it destroys claim provenance — every read would re-mint the
timestamp, so nobody could say when the trail was last actually examined.

### Per-record verified badges
Claims more than the verifier proves; correlation is payload-level. A row badge is decoration
wearing the costume of proof.

### A suggested attest dependency in the console core
The shipped implementation would be untestable in core's own CI, and one-way layering is why this
suite composes. A bridge costs one repository and buys the layering back.

### Reuse the incident ledger as the verification record
Incidents are an anomaly stream. The claim needs range, policy, actor, and per-chain
replaceability; the ledger stays what it is.

### Bind the bridge to attest's `@internal` seams (service or CLI)
An `@internal` seam is a contract with nobody, and the CLI turned out to be `@internal` too, with
no machine-readable output to pin. The bridge waits on §8's upstream seam; the core's recording
command keeps hosts honest meanwhile.

### A "partially verified" state
`Verified` already carries its exact range and instant; a separate state would imply a judgment
("partial") the verifier never made. The copy's precision is the state.

### Treat `Unverified` as a warning
A fresh attest host is healthy, not suspect. Warning-coloring the default trains operators to
ignore the one state that must never be ignorable: `Failed`.
