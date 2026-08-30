<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Incidents\IncidentStore;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Reads the durable anomaly ledger; rendering it deliberately has no recording side effect. */
final class Incidents extends Component
{
    public function __construct(
        private IncidentStore $incidents,
        private int $limit = 100,
    ) {}

    public function render(): View
    {
        return view('verdict-console::components.incidents', [
            'incidents' => $this->incidents->latest($this->limit),
        ]);
    }
}
