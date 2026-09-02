<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class SinkReview extends Component
{
    public function __construct(
        private EvidenceSinkPosture $sinkPosture,
        private Config $config,
    ) {}

    public function render(): View
    {
        $posture = $this->sinkPosture->read();
        $acknowledged = $posture->state === EvidenceRecordingState::Off
            && $this->config->get('verdict-console.evidence.accepted_off') === true;

        return view('verdict-console::components.sink-review', [
            'posture' => $posture,
            'reviewState' => $acknowledged ? 'off_acknowledged' : $posture->state->value,
        ]);
    }
}
