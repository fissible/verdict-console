<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use Illuminate\Database\Eloquent\Model;

/**
 * A row is one remembered Laravel AI invocation and its conversation for evidence correlation.
 * Rows are never updated: the store preserves the first observation.
 *
 * @property string $invocation_id
 * @property string $conversation_id
 */
final class ConversationInvocation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'invocation_id';

    protected $table = 'verdict_console_conversation_invocations';

    protected $guarded = [];
}
