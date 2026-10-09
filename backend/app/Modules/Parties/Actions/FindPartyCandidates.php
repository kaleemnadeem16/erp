<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Support\PartyCandidateAccess;
use Illuminate\Support\Collection;

/**
 * "Is this identity already here?" (ADR 0105). Looks for existing Parties that probably are the same person or organisation, so a person reuses
 * (or restores) one instead of creating a duplicate. It only **finds**: nothing is merged, overwritten or created, and a similar name alone is never
 * treated as the same identity (the answer is a list for a human to decide on). Archived candidates are discoverable only by someone who may restore them.
 */
final class FindPartyCandidates
{
    public const LIMIT = 20;

    /**
     * @param  array{name?: ?string, code?: ?string, tax_id?: ?string, registration_no?: ?string, email?: ?string, phone?: ?string}  $probe
     * @return list<array{id: int, code: string, name: string, kind: string, status: string, matched_on: list<string>}>
     */
    public function __invoke(array $probe, User $actor): array
    {
        PartyCandidateAccess::require($actor);
        $withArchived = PartyCandidateAccess::mayRestore($actor);

        /** @var Collection<int, array{party: Party, reasons: list<string>}> $found */
        $found = collect();
        $add = function (Party $party, string $reason) use (&$found): void {
            $entry = $found->get($party->id, ['party' => $party, 'reasons' => []]);
            if (! in_array($reason, $entry['reasons'], true)) {
                $entry['reasons'][] = $reason;
            }
            $found->put($party->id, $entry);
        };
        $parties = fn () => $withArchived ? Party::withTrashed() : Party::query();

        if (($code = $this->text($probe['code'] ?? null)) !== null) {
            foreach ($parties()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->limit(self::LIMIT)->get() as $party) {
                $add($party, 'code');
            }
        }
        if (($name = $this->normalisedName($probe['name'] ?? null)) !== null) {
            foreach ($parties()->whereRaw($this->normalisedNameSql('name').' = ?', [$name])->limit(self::LIMIT)->get() as $party) {
                $add($party, 'name');
            }
        }
        if (($tax = $this->identifier($probe['tax_id'] ?? null)) !== null) {
            foreach ($parties()->whereRaw($this->identifierSql('tax_id').' = ?', [$tax])->limit(self::LIMIT)->get() as $party) {
                $add($party, 'tax_id');
            }
        }
        if (($registration = $this->identifier($probe['registration_no'] ?? null)) !== null) {
            foreach ($parties()->whereRaw($this->identifierSql('registration_no').' = ?', [$registration])->limit(self::LIMIT)->get() as $party) {
                $add($party, 'registration_no');
            }
        }
        if (($email = $this->text($probe['email'] ?? null)) !== null) {
            foreach (PartyContact::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->limit(self::LIMIT)->pluck('party_id')->unique() as $partyId) {
                $party = $parties()->find($partyId);
                if ($party !== null) {
                    $add($party, 'email');
                }
            }
        }
        if (($phone = $this->digits($probe['phone'] ?? null)) !== null) {
            foreach (PartyContact::query()->whereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') = ?", [$phone])->limit(self::LIMIT)->pluck('party_id')->unique() as $partyId) {
                $party = $parties()->find($partyId);
                if ($party !== null) {
                    $add($party, 'phone');
                }
            }
        }

        return $found->take(self::LIMIT)->map(fn (array $entry): array => [
            'id' => $entry['party']->id,
            'code' => $entry['party']->code,
            'name' => $entry['party']->name,
            'kind' => $entry['party']->kind->value,
            'status' => $entry['party']->trashed() ? 'ARCHIVED' : $entry['party']->status->value,
            'matched_on' => $entry['reasons'],
        ])->values()->all();
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalisedName(?string $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : $this->squash($value);
    }

    private function squash(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($value)) ?? '') ?? '');
    }

    private function normalisedNameSql(string $column): string
    {
        return "trim(regexp_replace(regexp_replace(lower({$column}), '[^[:alnum:][:space:]]', '', 'g'), '[[:space:]]+', ' ', 'g'))";
    }

    private function identifier(?string $value): ?string
    {
        $value = $this->text($value);
        $clean = $value === null ? '' : preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));

        return $clean === '' || $clean === null ? null : $clean;
    }

    private function identifierSql(string $column): string
    {
        return "regexp_replace(lower(coalesce({$column}, '')), '[^[:alnum:]]', '', 'g')";
    }

    private function digits(?string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        return $digits === '' || $digits === null || strlen($digits) < 6 ? null : $digits;
    }
}
