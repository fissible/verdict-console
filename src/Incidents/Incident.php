<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Incidents;

use Illuminate\Database\Eloquent\Model;

/** A durable observation of an anomaly; it is never a copy of Verdict decision state. */
final class Incident extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'verdict_console_incidents';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'observed_at' => 'datetime',
        ];
    }
}
