<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Integrity extends Component
{
    public function __construct(private EvidenceIntegrity $integrity) {}

    public function render(): View
    {
        return view('verdict-console::components.integrity', ['chains' => $this->integrity->chains()]);
    }
}
