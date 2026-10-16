<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Brick\Math\BigDecimal;

/**
 * One configured final-net control: a typed check, advisory or mandatory, with its limit. The limit is part of the control's version, not chosen by the
 * seller and not derived from any actor — there are no per-actor or delegate ceilings here; an effective limit is resolved elsewhere (R9) into a control.
 * `id` is an opaque reference that must not encode the kind or the threshold, because it is the only part a cost-blind reader sees.
 * `exceptionable` is recorded for later; no bypass exists in this delivery.
 */
final readonly class NetControl
{
    public BigDecimal $value;

    public function __construct(
        public string $id,
        public int $version,
        public NetControlKind $kind,
        public ControlMode $mode,
        string|BigDecimal $value,
        public ?int $currencyId = null,
        public ControlBasis $basis = ControlBasis::Line,
        public bool $exceptionable = false,
    ) {
        if ($id === '' || trim($id) !== $id || $version < 1) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'A control needs an opaque id and a positive version.', 'control');
        }
        $text = (string) $value;
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'A control limit must be a plain non-negative decimal.', 'control');
        }
        $limit = BigDecimal::of($text);
        if ($kind->isPercent()) {
            if ($currencyId !== null || $limit->isGreaterThan(100) || $limit->strippedOfTrailingZeros()->getScale() > 4) {
                throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'A percentage limit is 0 to 100 with at most four places and has no currency.', 'control');
            }
        } elseif ($currencyId === null || $limit->strippedOfTrailingZeros()->getScale() > StorageAmount::SCALE || $limit->isGreaterThan(StorageAmount::MAX)) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'An amount limit needs a currency and storage-money precision.', 'control');
        }
        $this->value = $limit;
    }
}
