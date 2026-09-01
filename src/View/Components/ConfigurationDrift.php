<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Contracts\ConfigurationDriftQuery;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ConfigurationDrift extends Component
{
    public function __construct(
        private ConfigurationDriftQuery $drift,
        private ConfigurationInspection $inspection,
    ) {}

    public function render(): View
    {
        $result = $this->drift->observed();
        $currentFingerprints = [];

        foreach ($this->inspection->capabilities() as $capability) {
            $currentFingerprints[$capability->name] = $capability->configurationFingerprint;
        }

        return view('verdict-console::components.configuration-drift', [
            'result' => $result,
            'currentFingerprints' => $currentFingerprints,
        ]);
    }
}
