<?php

// RETD Task Management — Property Discovery classification & routing rules.
//
// A discovered property is auto-classified by the system at submission and
// routed to the correct management desk (spec §3 / §17 / Rules 1-3):
//
//   SELF-DECLARED (no formal valuation)  -> Account & Record Manager queue
//   VALUATION-REQUIRED                   -> Valuation Manager queue
//
// Managers retain the ability to override (e.g. the Account & Record Manager
// may refer a self-declared property to valuation — Path C).

return [

    // Property classifications treated as SELF-DECLARED with sufficient
    // declared / supporting value information (routed to A&R Manager queue).
    'self_declared' => [
        'Residential',
        'Vacant Land',
        'Agricultural',
        'Government',
        'Community',
    ],

    // Property classifications that require a formal valuation assessment
    // (routed directly to the Valuation Manager queue).
    'valuation_required' => [
        'Commercial',
        'Industrial',
        'Mixed Use',
        'Institutional',
    ],

    // Fallback for classifications matching neither list. Defaulting to
    // valuation keeps the decision with the Valuation Manager desk
    // (matches Rule 3 / Rule 4 management discretion).
    'default_path' => 'valuation',

    // System-wide Value Declaration Type routing (§4). The declaration type is
    // the primary routing signal once captured; the classification lists above
    // remain the fallback for records that predate the declaration model.
    'value_declaration_types' => [
        'SELF_DECLARED' => 'account',
        'DOCUMENTARY_VALUE' => 'account',
        'VALUATION_VALUE' => 'valuation',
    ],

    // Discovery evidence rules (§7/§9/§24). Configurable — not hard-coded.
    'evidence' => [
        'max_property_photos' => 3,
        'require_ownership_document_when_documentary' => true,
    ],

    // Label recorded as the classification decision source.
    'decision_source_automatic' => 'Automatic (RETD classification)',
    'decision_source_manager' => 'Management decision',
];
