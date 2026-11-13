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
    private const ADD_FIELDS = ['scope_ref', 'section_title', 'description', 'membership', 'choice_group_ref', 'is_baseline', 'source_kind', 'item_id', 'assembly_version_id', 'manual_measure', 'offered_quantity', 'offered_uom_id', 'unit_price', 'sort_order', 'discount', 'net_override', 'tax_declaration'];

    private const UPDATE_FIELDS = ['id', 'section_title', 'description', 'membership', 'choice_group_ref', 'is_baseline', 'item_id', 'assembly_version_id', 'manual_measure', 'offered_quantity', 'offered_uom_id', 'unit_price', 'sort_order', 'discount', 'net_override', 'tax_declaration'];

    /**
     * @param  array<string, int|string|null>  $header
     * @param  list<array<string, mixed>>  $add
     * @param  list<array<string, mixed>>  $update
     * @param  list<int>  $remove
     * @param  array{kind: string, value: string}|null  $documentDiscount  null with `$documentDiscountSet` removes the document discount
     */
    private function __construct(public array $header, public array $add, public array $update, public array $remove, public bool $documentDiscountSet = false, public ?array $documentDiscount = null) {}

    /** @param  array<string, mixed>  $input */
    public static function parse(array $input): self
    {
        $lines = ['add_lines', 'update_lines', 'remove_lines'];
        $documentSet = array_key_exists('document_discount', $input);
        $document = $documentSet ? self::discount($input['document_discount'], 'document_discount', ['PERCENT', 'AMOUNT']) : null;
        $header = DraftHeader::parse(array_diff_key($input, array_flip([...$lines, 'document_discount'])));
        $add = array_map(fn (mixed $line): array => self::line($line, self::ADD_FIELDS, 'add_lines', true), self::list($input['add_lines'] ?? [], 'add_lines'));
        $update = array_map(fn (mixed $line): array => self::line($line, self::UPDATE_FIELDS, 'update_lines', false), self::list($input['update_lines'] ?? [], 'update_lines'));
        $remove = array_map(static fn (mixed $id): int => is_int($id) && $id > 0 ? $id : throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A line identifier is a positive whole number.', 'remove_lines'), self::list($input['remove_lines'] ?? [], 'remove_lines'));

        $ids = array_merge(array_column($update, 'id'), $remove);
        if (count($ids) !== count(array_unique($ids))) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A line can be changed or removed once per update.', 'update_lines');
        }
        if ($header === [] && $add === [] && $update === [] && $remove === [] && ! $documentSet) {
            throw QuotationRefusal::make(QuotationRefusal::NOTHING_TO_CHANGE, 'The update changes nothing.');
        }

        return new self($header, $add, $update, $remove, $documentSet, $document);
    }

    /** @return array<string, mixed> */
    public function toIntent(): array
    {
        return ['header' => $this->header, 'add' => $this->add, 'update' => $this->update, 'remove' => $this->remove]
            + ($this->documentDiscountSet ? ['document_discount' => $this->documentDiscount] : []);
    }

    /** Does this change set or clear any discount (line or document)? The caller then also needs the discount duty. */
    public function touchesDiscount(): bool
    {
        return $this->documentDiscountSet || $this->anyLine('discount');
    }

    /** Does this change set or clear any final-net override? The caller then also needs the sensitive override duty. */
    public function touchesOverride(): bool
    {
        return $this->anyLine('net_override');
    }

    private function anyLine(string $field): bool
    {
        foreach ([...$this->add, ...$this->update] as $line) {
            if (array_key_exists($field, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A discount is `{kind, value}` or null (remove). PERCENT is 0-100 with at most four places; amounts are at most four places; zero is not a discount (remove it instead).
     *
     * @param  list<string>  $kinds
     * @return array{kind: string, value: string}|null
     */
    private static function discount(mixed $value, string $field, array $kinds): ?array
    {
        if ($value === null) {
            return null;
        }
        $bad = fn (string $why) => QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, $why, $field);
        if (! is_array($value) || array_keys($value) !== ['kind', 'value'] && array_keys($value) !== ['value', 'kind']) {
            throw $bad('A discount is an object with a kind and a value.');
        }
        $number = $value['value'];
        if (! in_array($value['kind'], $kinds, true) || ! (is_string($number) || is_int($number)) || preg_match('/^\d+(\.\d{1,4})?$/', (string) $number) !== 1) {
            throw $bad('A discount has a known kind and an exact positive decimal with at most four places, written as text.');
        }
        $number = (string) $number;
        if (bccomp($number, '0', 4) <= 0 || ($value['kind'] === 'PERCENT' && bccomp($number, '100', 4) > 0)) {
            throw $bad('A discount is greater than zero (remove it instead) and a percentage is at most 100.');
        }

        return ['kind' => $value['kind'], 'value' => $number];
    }

    /**
     * A manual line's tax nature and classification, with the reason for stating (or correcting) them. There is no null: evidence is superseded by a new version, never removed.
     *
     * @return array{nature: string, classification: string, reason: string}
     */
    private static function taxDeclaration(mixed $value, string $where): array
    {
        $bad = fn (string $why) => QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, $why, "{$where}.tax_declaration");
        if (! is_array($value) || array_diff(array_keys($value), ['nature', 'classification', 'reason']) !== [] || count($value) !== 3) {
            throw $bad('A tax declaration is an object with a nature, a classification and a reason.');
        }
        $classification = is_string($value['classification']) ? trim($value['classification']) : '';
        $reason = is_string($value['reason']) ? trim($value['reason']) : '';
        if (! in_array($value['nature'], ['GOODS', 'SERVICES'], true) || $classification === '' || mb_strlen($classification) > 80) {
            throw $bad('The nature is GOODS or SERVICES and the classification is 1 to 80 characters.');
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw QuotationRefusal::make(QuotationRefusal::REASON_REQUIRED, 'A tax declaration needs a reason of at most 500 characters.', "{$where}.tax_declaration");
        }

        return ['nature' => $value['nature'], 'classification' => $classification, 'reason' => $reason];
    }

    /** @return array{amount: string, reason: string}|null */
    private static function override(mixed $value, string $where): ?array
    {
        if ($value === null) {
            return null;
        }
        $bad = fn (string $why) => QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, $why, "{$where}.net_override");
        if (! is_array($value) || (array_keys($value) !== ['amount', 'reason'] && array_keys($value) !== ['reason', 'amount'])) {
            throw $bad('A final-net override is an object with an amount and a reason.');
        }
        $amount = $value['amount'];
        $reason = is_string($value['reason']) ? trim($value['reason']) : '';
        if (! (is_string($amount) || is_int($amount)) || preg_match('/^\d+(\.\d{1,4})?$/', (string) $amount) !== 1) {
            throw $bad('The override amount is an exact non-negative decimal with at most four places, written as text.');
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw QuotationRefusal::make(QuotationRefusal::REASON_REQUIRED, 'A final-net override needs a reason of at most 500 characters.', "{$where}.net_override");
        }

        return ['amount' => (string) $amount, 'reason' => $reason];
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
            'id', 'item_id', 'assembly_version_id', 'offered_uom_id' => $value === null && $key === 'offered_uom_id' ? null : (is_int($value) && $value > 0 ? $value : throw $bad('An identifier is a positive whole number.')),
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
            'discount' => self::discount($value, "{$where}.discount", ['PERCENT', 'PER_PRICED_UNIT', 'PER_LINE']),
            'net_override' => self::override($value, $where),
            'tax_declaration' => self::taxDeclaration($value, $where),
            'unit_price' => $value === null ? null : (is_string($value) || is_int($value) ? (string) $value : throw $bad('A unit price is an exact decimal written as text.')),
            default => throw $bad('This field is not accepted.'),
        };
    }
}
