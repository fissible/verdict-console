<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Doctor\Doctor as DoctorService;
use Fissible\VerdictConsole\Doctor\Finding;
use Fissible\VerdictConsole\Doctor\Severity;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Renders Doctor's single diagnostic run without deriving or changing any findings. */
final class Doctor extends Component
{
    public function __construct(private DoctorService $doctor) {}

    public function render(): View
    {
        $findings = $this->doctor->run();
        $errors = count(array_filter($findings, fn (Finding $finding): bool => $finding->severity === Severity::Error));

        return view('verdict-console::components.doctor', [
            'errors' => $errors,
            'findings' => $findings,
            'warnings' => count($findings) - $errors,
        ]);
    }
}
