<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Console\Commands;

use Fissible\VerdictConsole\Doctor\Doctor;
use Fissible\VerdictConsole\Doctor\Finding;
use Fissible\VerdictConsole\Doctor\Severity;
use Illuminate\Console\Command;

/**
 * Renders {@see Doctor}'s findings. It derives none of them itself — the findings are data so a UI
 * can render the same set later (VC-22) without parsing console output.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'verdict-console:doctor {--strict : Treat warnings as failures}';

    protected $description = 'Check that this application is wired to drive Verdict approvals through the console.';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run();

        if ($findings === []) {
            $this->components->info('Every console precondition is satisfied.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->renderFinding($finding);
        }

        $errors = array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Error);
        $warnings = count($findings) - count($errors);

        $this->newLine();
        $this->components->twoColumnDetail('Errors', (string) count($errors));
        $this->components->twoColumnDetail('Warnings', (string) $warnings);

        // Errors move the exit code; warnings need --strict. This mirrors verdict:validate, whose
        // advisory findings deliberately do not fail a build on their own.
        return $errors !== [] || ($warnings > 0 && $this->option('strict'))
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderFinding(Finding $finding): void
    {
        $label = $finding->severity === Severity::Error ? 'ERROR' : 'WARN';

        $this->newLine();
        $this->components->twoColumnDetail(
            sprintf('<fg=%s;options=bold>%s</> %s', $finding->severity === Severity::Error ? 'red' : 'yellow', $label, $finding->subject),
            $finding->code->value,
        );
        $this->line('  '.$finding->summary);
        $this->line('  <fg=gray>Fix:</> '.$finding->fix);
    }
}
