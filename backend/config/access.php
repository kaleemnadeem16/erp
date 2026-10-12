<?php

return [
    /*
    | Reviewed rollout inputs that attest an EXISTING company role is a genuinely full-access role (every registered capability). Only such a
    | role's holders receive an excluded sensitive duty through an automatic, all-capabilities grant (see App\Support\FullAccessMandates).
    | A role name, a description, an email or a permission count is never proof. Each entry:
    |   ['company_id' => 1, 'role_id' => 7, 'mandate' => 'ALL_REGISTERED_CAPABILITIES', 'approval_reference' => 'CHG-123', 'effective_from' => '2026-10-12']
    | Supplied as JSON in ACCESS_FULL_ACCESS_MANDATES; empty by default, so nothing is covered until someone approves it.
    */
    'full_access_mandates' => json_decode((string) env('ACCESS_FULL_ACCESS_MANDATES', '[]'), true) ?: [],
];
