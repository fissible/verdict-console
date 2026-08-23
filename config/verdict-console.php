<?php

declare(strict_types=1);

// Configuration for fissible/verdict-console. Every value below is a scaffold default; the keys are
// the design's decided defaults (docs/design/0001-verdict-console-design.md §7, §13) and are read by
// no runtime code yet.
return [

    /*
    |--------------------------------------------------------------------------
    | Real-time transport
    |--------------------------------------------------------------------------
    | Polling is the default because it needs no broadcasting infrastructure.
    | Set to "broadcast" once the host runs Reverb/Pusher. (Design §7.)
    */
    'transport' => env('VERDICT_CONSOLE_TRANSPORT', 'polling'),

    'polling' => [
        'interval_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Approver authority
    |--------------------------------------------------------------------------
    | Who may approve is the HOST's decision, delegated to a Laravel Gate. The
    | console never hard-codes authority; this only names the ability it checks.
    | (Design §7.)
    */
    'approvals' => [
        'gate' => 'approve-verdict-action',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ephemeral ingestion incidents
    |--------------------------------------------------------------------------
    |
    | Until VC-15 persists these into an incident ledger, the package's default
    | sink is a warning log line. Hosts with their own event listener may turn
    | that sink off without unregistering the event itself.
    |
    */
    'ingestion_incidents' => [
        'log' => env('VERDICT_CONSOLE_INGESTION_INCIDENT_LOG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration surface
    |--------------------------------------------------------------------------
    | Inspect-only in v1: the capability-configuration fingerprint is recorded
    | in every decision record, so a config write changes what the evidence
    | trail means. (Design §6.8, §13.)
    */
    'config_surface' => [
        'writable' => false,
    ],

];
