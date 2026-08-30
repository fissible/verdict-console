<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\Paginator;
use Illuminate\View\Component;

/**
 * Server-rendered audit table over the VC-13 evidence read boundary.
 *
 * Pagination deliberately happens in memory: VC-13 specifies one complete filtered projection and
 * has no limit/offset contract. A host with a table too large for that projection replaces
 * EvidenceQuery with a boundary suited to its own storage and audience rules.
 */
final class Evidence extends Component
{
    public function __construct(
        private EvidenceQuery $evidence,
        private ?string $disposition = null,
        private ?string $capability = null,
        private ?string $conversation = null,
        private int $perPage = 25,
    ) {}

    public function render(): View
    {
        $result = $this->evidence->search(new EvidenceFilter(
            disposition: $this->disposition,
            capability: $this->capability,
            conversationId: $this->conversation,
        ));

        $records = $result->records;

        usort($records, fn (EvidenceRecord $left, EvidenceRecord $right): int => $right->recordedAt <=> $left->recordedAt);

        $perPage = max(1, $this->perPage);
        $page = Paginator::resolveCurrentPage('page');
        $pages = max(1, (int) ceil(count($records) / $perPage));
        $pageRecords = array_slice($records, ($page - 1) * $perPage, $perPage);

        return view('verdict-console::components.evidence', [
            'conversation' => $this->conversation,
            'page' => $page,
            'pageRecords' => $pageRecords,
            'pages' => $pages,
            'result' => $result,
        ]);
    }
}
