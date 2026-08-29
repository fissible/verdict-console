<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Tests;

use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Illuminate\Foundation\Application;

/**
 * The heavy base case, for tests that must exercise the real Verdict + Laravel AI stack.
 *
 * {@see TestCase} deliberately boots only the console's provider so the unit suite stays fast and
 * independent of Verdict's boot. That is the right default and is unchanged. This class is the
 * explicit opposite: it boots all three providers because the approval round trip has no meaning
 * without them — the pause comes from Laravel AI, the receipt from Verdict, and the tissue between
 * them is what this package exists to be.
 *
 * Everything here is still hermetic. No network, no credentials: the provider is an
 * `openai_compatible` driver pointed at a URL that only ever answers through `Http::fake()`.
 */
abstract class EndToEndTestCase extends IntegrationTestCase
{
    /**
     * The provider name the end-to-end agents use.
     */
    public const string PROVIDER = 'console_e2e';

    /**
     * The model name that provider reports. Only ever echoed back by the faked transport.
     */
    public const string MODEL = 'console-e2e-model';

    /**
     * The base URL the faked transport answers for. Never resolved — `Http::fake()` intercepts
     * before DNS, so this host does not exist and must not.
     */
    public const string BASE_URL = 'https://openai-compatible.invalid/v1';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // An OpenAI-compatible provider is the cheapest real gateway to drive over a faked
        // transport: it posts to `chat/completions` through Laravel's HTTP client, which
        // `Http::fake()` intercepts. A driver-specific provider would work equally well; this one
        // needs no vendor-shaped envelope beyond the chat-completions body.
        $app['config']->set('ai.providers.'.self::PROVIDER, [
            'driver' => 'openai_compatible',
            'key' => 'not-a-real-key',
            'url' => self::BASE_URL,
            'models' => ['text' => ['default' => self::MODEL]],
        ]);

        // Titling a conversation would spend a second faked response on a turn nothing asserts on.
        $app['config']->set('ai.conversations.generate_title', false);

        // Exercise Verdict #290's config-driven migration stubs. A production host may rename
        // this table, and the round trip is meaningful only when the store and every migration
        // agree on that configured name.
        $app['config']->set('verdict.approvals.table', 'console_e2e_approval_receipts');

        // Verdict 0.12 deliberately fails closed without a host authorizer. This is test-only
        // wiring for the real round trip; the console provider must not supply an allow-all one.
        $app['config']->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);
    }

    /**
     * Create the tables the round trip genuinely needs.
     *
     * Verdict's shipped default approval store is the *database* one, and Laravel AI reconstructs a
     * paused tool call from conversation history — so both sets of tables are preconditions of the
     * round trip, not test scaffolding. The console's own projections that listen to every run —
     * incidents, and the invocation ↔ conversation correlation — belong here for the same reason: a
     * completed prompt writes to them, and a suite that forgot one would log a projection failure on
     * every turn.
     */
    protected function migrateRoundTripTables(): void
    {
        $verdict = dirname(__DIR__).'/vendor/fissible/verdict/database/migrations';
        $ai = dirname(__DIR__).'/vendor/laravel/ai/database/migrations';

        (require $verdict.'/create_verdict_approval_receipts_table.php.stub')->up();
        (require $verdict.'/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
        (require $verdict.'/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();
        (require $ai.'/2026_01_11_000001_create_agent_conversations_table.php')->up();
        (require dirname(__DIR__).'/database/migrations/create_verdict_console_incidents_table.php.stub')->up();
        (require dirname(__DIR__).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();
    }

    /**
     * A chat-completions body carrying a single tool call.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function toolCallResponse(string $toolCallId, string $toolName, array $arguments): array
    {
        return [
            'model' => self::MODEL,
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $toolCallId,
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => json_encode($arguments)],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }

    /**
     * A chat-completions body carrying a final assistant message.
     *
     * @return array<string, mixed>
     */
    protected function textResponse(string $text): array
    {
        return [
            'model' => self::MODEL,
            'choices' => [[
                'message' => ['content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }

    /**
     * The configured Verdict receipt table, resolved the way the store resolves it.
     *
     * It lives beside the config line that renames the table, not in one test file. Verdict 0.11
     * (#290) made published stubs read table names from config, and this suite sets a non-default
     * one so the round trip exercises that rather than tolerating it. A fixture reading
     * `verdict_approval_receipts` directly would then fail with "no such table" and read as a
     * migration fault -- so the helper belongs where anyone writing a second end-to-end file will
     * find it.
     */
    protected function approvalReceiptTable(): string
    {
        return (string) config('verdict.approvals.table', 'verdict_approval_receipts');
    }
}
