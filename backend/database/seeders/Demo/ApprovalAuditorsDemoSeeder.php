<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use Database\Seeders\Demo\Concerns\CreatesDemoRolePersonas;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Reading everyone's approval spending limits and the free text written on decisions are capabilities an administrator grants (ADR 0087,
 * Q35), not part of reading the approval history. Two company roles, built the way an administrator would in Access Administration,
 * show the difference on the approvals the demo already holds in every state:
 *
 *  - "Approvals auditor" holds history plus `approvals.authority.view` and `approvals.reason.view`: sees the decisions, each person's
 *    limit and the reasons written on them;
 *  - "Approvals history reader" holds only `approvals.history.view`: sees the outcome, who decided and when, but not the limits and
 *    not the reasons (the screen says the reason is not shown).
 *
 * Neither can change an approval, a policy or a limit. Create-once, like every demo role.
 */
final class ApprovalAuditorsDemoSeeder extends Seeder
{
    use CreatesDemoRolePersonas;
    use EnsuresDemoEnvironment;

    public const AUDITOR_ROLE = 'Approvals auditor';

    public const AUDITOR_EMAIL = 'demo-auditor@erp.x1techs.com';

    public const READER_ROLE = 'Approvals history reader';

    public const READER_EMAIL = 'demo-historian@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $this->rolePersona($admin, self::AUDITOR_ROLE, 'Reads approval history, everyone\'s limits and the reasons written on decisions; changes nothing.',
            ['approvals.history.view', 'approvals.authority.view', 'approvals.reason.view'], self::AUDITOR_EMAIL, 'Demo Approvals Auditor');
        $this->rolePersona($admin, self::READER_ROLE, 'Reads who decided what and when, without limits or reasons.',
            ['approvals.history.view'], self::READER_EMAIL, 'Demo Approvals History Reader');
    }
}
