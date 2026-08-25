<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing this console told somebody about one paused approval.
 *
 * A row exists because a notification was *claimed*, not because it arrived: `delivered_at` and
 * `failed_at` are both null while it is in flight. That distinction is the point — an operator asking
 * "was anyone told?" needs to tell "never attempted" from "attempted and lost", and a table that only
 * recorded successes could answer neither. (Design §6.2.)
 *
 * @property string $id
 * @property string $pending_approval_id
 * @property string $notification_key
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 */
final class ApprovalNotification extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'verdict_console_approval_notifications';

    protected $guarded = [];

    /** Claimed, and neither delivered nor failed yet. */
    public function isInFlight(): bool
    {
        return $this->delivered_at === null && $this->failed_at === null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
