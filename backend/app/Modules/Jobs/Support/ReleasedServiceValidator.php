<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support;

/**
 * Shape check for a {@see ReleasedServiceInput}, mirroring the foundation tables' own limits so a later receiver cannot hand the database something it
 * will refuse. Pure: reads nothing, writes nothing, grants nothing and is not an entry point.
 */
final class ReleasedServiceValidator
{
    public function validate(ReleasedServiceInput $input): void
    {
        $this->bounded($input->jobCode, 40, 'job_code');
        $this->bounded($input->sourceContractVersion, 40, 'source_contract_version');
        $this->bounded($input->targetPurpose, 60, 'target_purpose');
        $this->bounded($input->businessIdentity, 120, 'business_identity');
        $this->bounded($input->baselineFingerprint, 128, 'baseline_fingerprint');
        $this->bounded($input->completionRuleVersion, 40, 'completion_rule_version');
        if (trim($input->scopeDescription) === '') {
            throw new JobsException('A baseline needs a scope description.', 'scope_description', 'required');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $input->intentHash) !== 1) {
            throw new JobsException('The intent hash must be 64 lowercase hex characters.', 'intent_hash', 'invalid');
        }
    }

    private function bounded(string $value, int $max, string $field): void
    {
        if (trim($value) === '') {
            throw new JobsException("'{$field}' is required.", $field, 'required');
        }
        if (strlen($value) > $max) {
            throw new JobsException("'{$field}' is longer than {$max} characters.", $field, 'too_long');
        }
    }
}
