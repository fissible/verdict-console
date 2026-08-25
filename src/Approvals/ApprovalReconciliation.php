<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** One durable record that the console could not finish a Verdict-approved continuation. */
final class ApprovalReconciliation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'verdict_console_approval_reconciliations';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phase' => ResumeFailurePhase::class,
            'detected_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    /** @property string $id */
    /** @property string $pending_approval_id */
    /** @property ResumeFailurePhase $phase */
    /** @property Carbon $detected_at */
    /** @property Carbon|null $abandoned_at */
}
