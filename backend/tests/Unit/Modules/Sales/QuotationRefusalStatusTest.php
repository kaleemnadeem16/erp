<?php

namespace Tests\Unit\Modules\Sales;

use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\Quotation\QuotationRefusalStatus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** QL1b-2 — the exact HTTP status of every quotation draft refusal is decided once, in writing, and a new code cannot ship without a decision. */
class QuotationRefusalStatusTest extends TestCase
{
    public function test_every_refusal_code_has_an_explicit_status(): void
    {
        $codes = array_values((new ReflectionClass(QuotationRefusal::class))->getConstants());

        $this->assertEqualsCanonicalizing($codes, array_keys(QuotationRefusalStatus::MAP), 'Each QuotationRefusal code needs a documented HTTP status, and the map lists no code that does not exist.');
    }

    public function test_the_documented_mapping(): void
    {
        $this->assertSame(404, QuotationRefusalStatus::for(QuotationRefusal::QUOTATION_NOT_FOUND));
        $this->assertSame(409, QuotationRefusalStatus::for(QuotationRefusal::REQUEST_KEY_REUSED));
        // Stale evidence and a state that refuses the operation stay 422 with their reason code (api-conventions.md).
        foreach ([QuotationRefusal::QUOTATION_DRAFT_STALE, QuotationRefusal::QUOTATION_NOT_EDITABLE, QuotationRefusal::REQUEST_KEY_REQUIRED, QuotationRefusal::INVALID_INPUT] as $code) {
            $this->assertSame(422, QuotationRefusalStatus::for($code), $code);
        }
        $this->assertSame([QuotationRefusal::QUOTATION_NOT_FOUND, QuotationRefusal::REQUEST_KEY_REUSED], array_keys(array_filter(QuotationRefusalStatus::MAP, fn (int $status): bool => $status !== 422)), 'Only not-found is 404 and only a reused key is 409.');
        // A kernel code (pricing, FX, rounding) that this map does not own is a plain business refusal.
        $this->assertSame(422, QuotationRefusalStatus::for('FX_RATE_MISSING'));
        $this->assertSame(422, QuotationRefusalStatus::for(null));
    }
}
