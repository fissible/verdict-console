<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Reviews\ReviewQueue;
use Fissible\VerdictConsole\Reviews\ReviewVerbs;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Server-rendered, record-only lane for scoped Verdict reviews. */
final class Reviews extends Component
{
    public function __construct(private ReviewQueue $queue, private ReviewVerbs $verbs) {}

    public function render(): View
    {
        return view('verdict-console::components.reviews', [
            'queue' => $this->queue->items(),
            'verbs' => $this->verbs,
        ]);
    }
}
