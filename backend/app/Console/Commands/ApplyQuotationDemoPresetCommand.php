<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\DomainInvariantException;
use App\Modules\Administration\Actions\ApplyQuotationDemoPreset;
use App\Modules\Administration\Support\QuotationDemoPresets;
use Illuminate\Console\Command;

/**
 * LOCAL DEMO ONLY (ADR 0156/0159/0161): flips the main demo login between named quotation presets so the owner can see each permission rule on the screens. The change is made by the
 * synthetic access operator through the real, guarded access Actions; unrelated rights of the login are kept. Needs the local preview (APP_ENV=local, SALES_DEV_QUOTATION_DUTIES=true).
 */
final class ApplyQuotationDemoPresetCommand extends Command
{
    protected $signature = 'sales:demo-preset
        {preset? : One of the preset names (see --list)}
        {--list : Show the presets and what they give}
        {--revoke : Same as the "off" preset}';

    protected $description = 'LOCAL DEMO ONLY: switch the main demo login to a named quotation permission preset (off, viewer, preparer, manager, governor, full, ...)';

    public function handle(ApplyQuotationDemoPreset $apply): int
    {
        if ($this->option('list') || ($this->argument('preset') === null && ! $this->option('revoke'))) {
            $this->table(['Preset', 'What it gives'], collect(QuotationDemoPresets::all())->map(fn (array $p, string $name): array => [$name, $p['about']])->values()->all());

            return self::SUCCESS;
        }
        $name = $this->option('revoke') ? 'off' : (string) $this->argument('preset');
        try {
            $result = $apply($name);
        } catch (DomainInvariantException $refusal) {
            $this->components->error('Refused: '.$refusal->getMessage());

            return self::FAILURE;
        }
        $this->components->info("{$result['email']} is now on the \"{$result['preset']}\" preset: {$result['about']}");
        $this->table(['Duty', 'Held', 'Scope'], array_map(fn (array $r): array => [$r['duty'], $r['held'] ? 'yes' : 'no', $r['scope']], $result['rights']));

        return self::SUCCESS;
    }
}
