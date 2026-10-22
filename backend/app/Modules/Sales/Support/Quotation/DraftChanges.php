<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Support\Pricing\PricingSourceKind;

/**
 * The typed change set of one draft update: header fields, lines to add, lines to change (by id) and lines to remove. Parsing is strict — an unknown key, a
 * float where an exact decimal is required, a source kind that cannot be prepared yet, or anything claiming approval, cost, tax or a base-currency amount is
 * refused rather than ignored. `toIntent()` is the normalised form the idempotency hash covers, so the same change written in a different key order is the same request.
 */
final readonly class DraftChanges
{
    private const ADD_FIELDS = ['scope_ref', 'section_title', 'description', 'membership', 'choice_group_ref', 'is_baseline', 'source_kind', 'item_id', 'manual_measure', 'offered_quantity', 'offered_uom_id', 'unit_price', 'sort_order'];

    private const UPDATE_FIELDS = ['id', 'section_title', 'description', 'membership', 'choice_group_ref', 'is_baseline', 'item_id', 'manual_measure', 'offered_quantity', 'offered_uom_id', 'unit_price', 'sort_order'];

    /**
     * @param  array<string, int|string|null>  $header
     * @param  list<array<string, mixed>>  $add
     * @param  list<array<string, mixed>>  $update
     * @param  list<int>  $remove
     */
    private function __construct(public array $header, public array $add, public array $update, public array $remove) {}

    /** @param  array<string, mixed>  $input */
    public static function parse(array $input): self
    {
        $lines = ['add_lines', 'update_lines', 'remove_lines'];
        $header = DraftHeader::parse(array_diff_key($input, array_flip($lines)));
        $add = array_map(fn (mixed $line): array => self::line($line, self::ADD_FIELDS, 'add_lines', true), self::list($input['add_lines'] ?? [], 'add_lines'));
        $update = array_map(fn (mixed $line): array => self::line($line, self::UPDATE_FIELDS, 'update_lines', false), self::list($input['update_lines'] ?? [], 'update_lines'));
        $remove = array_map(static fn (mixed $id): int => is_int($id) && $id > 0 ? $id : throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A line identifier is a positive whole number.', 'remove_lines'), self::list($input['remove_lines'] ?? [], 'remove_lines'));

        $ids = array_merge(array_column($update, 'id'), $remove);
        if (count($ids) !== count(array_unique($ids))) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A line can be changed or removed once per update.', 'update_lines');
        }
        if ($header === [] && $add === [] && $update === [] && $remove === []) {
            throw QuotationRefusal::make(QuotationRefusal::NOTHING_TO_CHANGE, 'The update changes nothing.');
        }

        return new self($header, $add, $update, $remove);
    }

    /** @return array<string, mixed> */
    public function toIntent(): array
    {
        return ['header' => $this->header, 'add' => $this->add, 'update' => $this->update, 'remove' => $this->remove];
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, "[{$field}] is a list.", $field);
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function line(mixed $line, array $allowed, string $where, bool $adding): array
    {
        if (! is_array($line)) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A line is an object of typed fields.', $where);
        }
        foreach (array_keys($line) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, "[{$key}] is not a field a draft line accepts.", "{$where}.{$key}");
            }
        }
        $out = [];
        foreach ($line as $key => $value) {
            $out[$key] = self::value($key, $value, $where);
        }
        if ($adding) {
            foreach (['source_kind', 'offered_quantity'] as $required) {
                if (! array_key_exists($required, $out)) {
                    throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, "A new line needs [{$required}].", "{$where}.{$required}");
                }
            }
        } elseif (! isset($out['id'])) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A changed line names its id.', "{$where}.id");
        }

        return $out;
    }

    private static function value(string $key, mixed $value, string $where): mixed
    {
        $bad = fn (string $why) => QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, $why, "{$where}.{$key}");

        return match ($key) {
            'id', 'item_id', 'offered_uom_id' => $value === null && $key === 'offered_uom_id' ? null : (is_int($value) && $value > 0 ? $value : throw $bad('An identifier is a positive whole number.')),
            'sort_order' => is_int($value) && $value >= 0 ? $value : throw $bad('The order is a whole number.'),
            'is_baseline' => is_bool($value) ? $value : throw $bad('This is true or false.'),
            'section_title' => $value === null || (is_string($value) && mb_strlen(trim($value)) <= 200) ? ($value === null || trim($value) === '' ? null : trim($value)) : throw $bad('A section title is at most 200 characters.'),
            'description' => is_string($value) && trim($value) !== '' ? trim($value) : throw $bad('A description is required.'),
            'manual_measure' => is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= 100 ? trim($value) : throw $bad('A measure is 1 to 100 characters.'),
            'scope_ref', 'choice_group_ref' => $value === null && $key === 'choice_group_ref' ? null : (is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= 80 ? trim($value) : throw $bad('A reference is 1 to 80 characters.')),
            'membership' => is_string($value) && LineMembership::tryFrom($value) !== null ? $value : throw $bad('Membership is BASE, OPTIONAL or CHOOSE_ONE.'),
            'source_kind' => is_string($value) && PricingSourceKind::tryFrom($value) !== null ? $value : throw $bad('The source kind is not recognised.'),
            // Exact decimals travel as text (or a whole number): a float has already lost the value the user typed.
            'offered_quantity' => is_string($value) || is_int($value) ? (string) $value : throw $bad('A quantity is an exact decimal written as text.'),
            'unit_price' => $value === null ? null : (is_string($value) || is_int($value) ? (string) $value : throw $bad('A unit price is an exact decimal written as text.')),
            default => throw $bad('This field is not accepted.'),
        };
    }
}
