<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

/** Resolved declared facts; persistence on a source is the future owning adapter's responsibility. */
final readonly class TaxClassification
{
    public function __construct(
        public SourceKind $source,
        public ?TaxNature $nature,
        public ?string $code,
        public string $provenance,
    ) {}

    public static function resolve(SourceKind $source, ?TaxNature $nature, ?string $explicit, ?string $groupDefault, ?string $goodsDefault, ?string $servicesDefault): self
    {
        if ($source === SourceKind::Assembly) {
            $nature ??= TaxNature::Services;
        }
        // A manual line must declare both nature and classification; never invent either from company defaults.
        if ($source === SourceKind::Manual) {
            return new self($source, $nature, self::nonblank($explicit), 'manual_explicit');
        }
        $candidates = ['explicit' => $explicit, 'group_default' => $groupDefault, 'company_nature_default' => match ($nature) {
            TaxNature::Goods => $goodsDefault,
            TaxNature::Services => $servicesDefault,
            default => null,
        }];
        foreach ($candidates as $provenance => $code) {
            if (self::nonblank($code) !== null) {
                return new self($source, $nature, self::nonblank($code), $provenance);
            }
        }

        return new self($source, $nature, null, 'unresolved');
    }

    private static function nonblank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
