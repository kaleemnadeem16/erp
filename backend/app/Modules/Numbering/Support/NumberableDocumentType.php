<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use Carbon\CarbonImmutable;

/**
 * The one contract a numberable document implements (ADR 0079 §2): "I am a numberable document, my type is X, these variables are
 * available, this is my numbering context, this is when I become official." Everything else — series, versions, counters,
 * the log, the screens, the tester — is the framework's.
 */
interface NumberableDocumentType
{
    /** Stable key, e.g. PURCHASE_ORDER. */
    public function key(): string;

    public function label(): string;

    /** The owning module, e.g. 'purchasing'; its settings link into Settings → Document Numbering. */
    public function module(): string;

    /** Plain words for when the number is taken, shown beside the date explanation: "when it is issued". */
    public function becomesOfficial(): string;

    /** @return list<VariableSupply> the document variables this type can supply */
    public function variables(): array;

    /** @return list<string> keys among variables() that may be used as counter-scope dimensions */
    public function dimensions(): array;

    /** @return list<string> attributes this type exposes to series routing rules */
    public function routingAttributes(): array;

    public function defaultTemplate(): string;

    public function defaultScope(): CounterScope;

    public function defaultContinuity(): Continuity;

    /** A statutory type returns true: its policy is fixed and the engine refuses to change it. */
    public function continuityLocked(): bool;

    /** The permission that lets someone change this type's series. */
    public function managePermission(): string;

    /** A realistic context for the tester and the uniqueness check; never persisted. */
    public function sampleContext(CarbonImmutable $at): NumberingContext;

    /** @return array<string, list<string>> dimension key => the values that exist today (branches, warehouses…) */
    public function knownDimensionValues(): array;
}
