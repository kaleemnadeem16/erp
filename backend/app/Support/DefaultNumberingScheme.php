<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\NumberingScheme;
use App\Models\Sequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * `prefix` + zero-padded `next_number`, e.g. INV-0001. Both are per-(company,
 * doc_type) columns on `sequences` — rung 1 of the variation ladder
 * (docs/architecture/extensibility.md), so a company's format is a data change,
 * never a deploy.
 *
 * Gapless under concurrent load: the row is locked with lockForUpdate() before the
 * number is read, so two transactions never see the same next_number — the second
 * blocks until the first commits. See docs/architecture/invariants.md#8.
 */
class DefaultNumberingScheme implements NumberingScheme
{
    public function next(string $docType): string
    {
        return DB::transaction(function () use ($docType) {
            $sequence = Sequence::query()
                ->where('doc_type', $docType)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = $this->createRow($docType);
            }

            $number = $sequence->next_number;

            $sequence->increment('next_number');

            return $sequence->prefix.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Auto-vivifies the sequence row on first use so nothing has to seed it ahead of
     * time. Two requests can still race to create the same (company, doc_type) row —
     * Postgres serialises the second INSERT behind the first commit and then rejects
     * it on the unique constraint, so the loser just re-fetches the now-locked row
     * the winner created instead of numbering from a second, duplicate row.
     */
    private function createRow(string $docType): Sequence
    {
        try {
            // next_number/padding are passed explicitly, not left to the column
            // defaults — Eloquent doesn't re-fetch a row after INSERT, so the
            // in-memory model would otherwise carry null for both.
            return Sequence::create(['doc_type' => $docType, 'next_number' => 1, 'padding' => 4]);
        } catch (UniqueConstraintViolationException) {
            return Sequence::query()->where('doc_type', $docType)->lockForUpdate()->firstOrFail();
        }
    }
}
