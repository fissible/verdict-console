<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Chat\ConfiguredChatEntry;
use Fissible\VerdictConsole\Contracts\ChatEntry;
use Fissible\VerdictConsole\Exceptions\ChatEntryNotConfigured;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The shipped entry: the authenticated user is the participant, and the entry agent is the
 * resumable-agent key the host names in config. Nominating a *key* rather than an agent is the
 * point — it is the same key VC-2 rebuilds after a pause, so a chat the console starts is resumable
 * by construction rather than by hope.
 */
it('names the configured resumable-agent key as the entry for any participant', function (): void {
    config()->set('verdict-console.chat.entry_key', 'support@v2');

    $entry = app(ChatEntry::class);

    expect($entry)->toBeInstanceOf(ConfiguredChatEntry::class)
        ->and($entry->entryKeyFor(new GenericUser(['id' => 7])))->toBe('support@v2');
});

it('attaches a new conversation to the authenticated user by default', function (): void {
    $user = new GenericUser(['id' => 7]);

    expect(app(ChatEntry::class)->participantFor($user))->toBe($user);
});

/** No key is shipped: the package cannot know which of the host's agents should greet a user. */
it('refuses to name an entry until the host configures one, and says where', function (): void {
    expect(config('verdict-console.chat.entry_key'))->toBeNull('The shipped config must leave the key unset.');

    expect(fn (): string => app(ChatEntry::class)->entryKeyFor(new GenericUser(['id' => 7])))
        ->toThrow(ChatEntryNotConfigured::class, 'verdict-console.chat.entry_key');
});

it('treats a blank configured key as unconfigured', function (string $blank): void {
    config()->set('verdict-console.chat.entry_key', $blank);

    expect(fn (): string => app(ChatEntry::class)->entryKeyFor(new GenericUser(['id' => 7])))
        ->toThrow(ChatEntryNotConfigured::class);
})->with(['empty' => '', 'whitespace' => '   ']);

it('binds the shipped entry to a contract a host may replace', function (): void {
    $replacement = new class implements ChatEntry
    {
        public function participantFor(Authenticatable $user): object
        {
            return new GenericUser(['id' => 99]);
        }

        public function entryKeyFor(object $participant): string
        {
            return 'tenant-router@v1';
        }
    };

    app()->instance(ChatEntry::class, $replacement);

    expect(app(ChatEntry::class))->toBe($replacement);
});
