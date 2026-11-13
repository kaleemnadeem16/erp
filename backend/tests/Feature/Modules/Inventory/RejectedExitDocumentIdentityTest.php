<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PackageDrawPlanner;
use App\Modules\Inventory\Actions\PostRejectedStockExit;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Support\StockQuantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRejectedStockWorld;

class RejectedExitDocumentIdentityTest extends RecordPackageCheckpointTest
{
    use BuildsRejectedStockWorld;

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool, bool}> */
    public static function identities(): iterable
    {
        $bound = ['document_type' => 'SUPPLIER_RETURN_LINE', 'document_id' => 101];
        $cases = [
            'identical' => [$bound, $bound, true],
            'changed id' => [$bound, array_replace($bound, ['document_id' => 202]), false],
            'changed type' => [$bound, array_replace($bound, ['document_type' => 'STOCK_SCRAP_LINE']), false],
            'removed identity' => [$bound, [], false],
            'omitted to null' => [[], ['document_type' => null, 'document_id' => null], true],
            'null to omitted' => [['document_type' => null, 'document_id' => null], [], true],
            'added identity' => [[], $bound, false],
            'id only changed' => [['document_id' => 101], ['document_id' => 202], false],
            'type only changed' => [['document_type' => 'A'], ['document_type' => 'B'], false],
        ];
        foreach ([false, true] as $missEarlyLookup) {
            foreach ($cases as $name => $case) {
                yield ($missEarlyLookup ? 'under lock: ' : 'early: ').$name => [...$case, $missEarlyLookup];
            }
        }
    }

    #[DataProvider('identities')]
    public function test_document_identity_is_part_of_replay_intent_without_rehashing_historical_rows(array $original, array $retry, bool $allowed, bool $missEarlyLookup): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $input = $this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [
            ['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1],
        ]);
        $post = app(PostRejectedStockExit::class);
        $exit = $post(array_replace($input, $original), $this->world['exiter']);
        $this->flush();
        // The pre-fix hash intentionally lacks document fields. Existing immutable rows must keep replaying.
        $legacyHash = hash('sha256', json_encode([
            'actor' => $this->world['exiter']->id, 'kind' => 'SUPPLIER_RETURN', 'line' => $receipt->lines()->sole()->id,
            'quantity' => (string) StockQuantity::positive('5'), 'reason' => $input['reason'], 'change' => '',
            'portion' => app(PackageDrawPlanner::class)->parse($input['portion']),
        ], JSON_THROW_ON_ERROR));
        $this->assertSame($legacyHash, $exit->payload_hash);
        $before = $this->effects();
        $scopes = RejectedStockExit::getAllGlobalScopes();
        $lookups = 0;
        if ($missEarlyLookup) {
            // Deterministically exercise the second check after a missed initial lookup; real lock scheduling is covered separately.
            RejectedStockExit::addGlobalScope('miss_initial_exit_lookup', function (Builder $query) use (&$lookups): void {
                if (++$lookups === 1) {
                    $query->whereRaw('false');
                }
            });
        }
        try {
            try {
                $result = $post(array_replace($input, $retry), $this->world['exiter']);
                $this->assertTrue($allowed, 'Changed immutable document identity must not replay.');
                $this->assertSame($exit->id, $result->id);
            } catch (DomainInvariantException $error) {
                $this->assertFalse($allowed, $error->getMessage());
                $this->assertStringContainsString('different rejected-stock exit', $error->getMessage());
            }
        } finally {
            RejectedStockExit::setAllGlobalScopes($scopes);
        }
        if ($missEarlyLookup) {
            $this->assertSame(2, $lookups, 'The under-lock lookup must actually run.');
        }
        $this->flush();
        $this->assertSame($before, $this->effects(), 'Replay or refusal must leave stock and immutable evidence unchanged.');
    }

    /** @return array<string, mixed> */
    private function effects(): array
    {
        $effects = [];
        foreach (['stock_moves', 'stock_layers', 'stock_layer_consumptions', 'stock_valuation_entries', 'rejected_stock_exits',
            'inventory_package_operations', 'inventory_package_states', 'inventory_package_contributions'] as $table) {
            $effects[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $effects;
    }
}
