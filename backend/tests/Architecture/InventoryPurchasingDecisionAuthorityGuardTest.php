<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Issue #91 static guard. An Action in the Inventory or Purchasing module that checks authority (`ActionAuthorization`, `->can(`, `hasPermissionTo`,
 * `hasAnyPermission`) must take the decision INSIDE its database transaction from the stored actor (`DecisionActor::require|requireAll|fresh`).
 * A check made only before the transaction can be overtaken by a revocation or suspension that commits in between.
 *
 * Scope: Inventory and Purchasing Actions only. Other modules (Approvals admin Actions, Estimating, Administration, Accounting, Company, Numbering, Imports)
 * are NOT covered and not cleared by this guard; extending it is out of scope here and tracked in issue #112.
 *
 * This is a source scan, not a proof: it cannot see a decision hidden in a helper, which is why every exemption below is named and reasoned. A new
 * Action that checks authority without a decision inside its transaction fails until it is fixed or deliberately listed.
 */
class InventoryPurchasingDecisionAuthorityGuardTest extends TestCase
{
    /** Actions exempt from the rule, relative to app/Modules, each with the reason it is safe (reviewed 2026-11-12, Reviewer A R3). */
    private const ALLOWED = [
        // Delegators: the early check only fails fast; the state change is made by an Action that decides inside its transaction.
        'Inventory/Actions/ArchiveLocation.php' => 'delegates to UpdateLocation, which decides inside its transaction',
        'Inventory/Actions/IssueGroupedStock.php' => 'early check only; delegates to IssuePackagedStock (requireAll issue+view inside its transaction)',
        'Inventory/Actions/TransferGroupedStock.php' => 'early check only; delegates to TransferPackagedStock (requireAll transfer+view inside its transaction)',
        // Sub-actions run inside a parent transaction with the parent\'s FRESH actor (the parent reassigns $actor = DecisionActor::require(...) first).
        'Inventory/Actions/ReverseGroupedReceiptLine.php' => 'called by ReverseGoodsReceipt with its fresh DecisionActor inside the transaction',
        'Inventory/Actions/RecordReceiptPackageEvidence.php' => 'called by PostGoodsReceipt with its fresh DecisionActor inside the transaction',
        'Inventory/Actions/RecordQaDispositionEvidence.php' => 'called by PostQualityDisposition with its fresh DecisionActor inside the transaction',
        'Inventory/Actions/PackageCountCapture.php' => 'called by AddPhysicalCountLine with its fresh DecisionActor inside the transaction',
        'Purchasing/Actions/ReconcilePurchaseOrderFx.php' => 'no transaction of its own: runs inside the calling purchase-order Action\'s transaction and decides the override duty there with DecisionActor::fresh',
        // Read-only: no state change, so there is nothing to decide at commit.
        'Inventory/Actions/PreviewGroupedActivation.php' => 'read-only preview',
        'Inventory/Actions/PreviewGroupedReceiptReversal.php' => 'read-only preview',
        'Inventory/Actions/PreviewQaDispositionPortion.php' => 'read-only preview',
        'Inventory/Actions/PreviewRejectedStockExit.php' => 'read-only preview',
        'Inventory/Actions/ReadPackageBalances.php' => 'read-only query',
        'Inventory/Actions/ReplayPackageCheckpoint.php' => 'read-only replay of recorded evidence',
        'Inventory/Actions/SuggestPackageDraw.php' => 'read-only suggestion',
        'Purchasing/Actions/PreviewGoodsReceiptReversal.php' => 'read-only preview',
    ];

    private const DECISION = '/DecisionActor::(?:require|requireAll|fresh)\(/';

    private const AUTHORITY = '/ActionAuthorization::|->can\(|->hasPermissionTo\(|->hasAnyPermission\(/';

    /** Source with comments removed. */
    private static function code(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return list<string> the text of every `DB::transaction(...)` / `->transaction(...)` call, arguments included */
    private static function transactions(string $code): array
    {
        $bodies = [];
        $tokens = token_get_all('<?php '.$code);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (! (is_array($t) && $t[0] === T_STRING && $t[1] === 'transaction')) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $body = '';
            for (; $j < $n; $j++) {
                $x = $tokens[$j];
                $text = is_array($x) ? $x[1] : $x;
                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')') {
                    $depth--;
                }
                $body .= $text;
                if ($depth === 0) {
                    break;
                }
            }
            $bodies[] = $body;
        }

        return $bodies;
    }

    /** True when the Action checks authority but takes no decision inside a transaction. */
    public static function decidesOnlyBeforeTheTransaction(string $source): bool
    {
        $code = self::code($source);
        if (preg_match(self::AUTHORITY, $code) !== 1) {
            return false;
        }
        foreach (self::transactions($code) as $body) {
            if (preg_match(self::DECISION, $body) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> paths relative to app/Modules */
    private function actionFiles(): array
    {
        $root = base_path('app/Modules');
        $found = [];
        foreach (['Inventory', 'Purchasing'] as $module) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$module}/Actions", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $found[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($found);

        return $found;
    }

    public function test_no_inventory_or_purchasing_action_decides_authority_only_before_its_transaction(): void
    {
        $offenders = [];
        foreach ($this->actionFiles() as $path) {
            if (isset(self::ALLOWED[$path])) {
                continue;
            }
            if (self::decidesOnlyBeforeTheTransaction((string) file_get_contents(base_path("app/Modules/{$path}")))) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders, 'These Actions check authority but take no DecisionActor decision inside their DB::transaction. Decide from the stored actor inside the transaction (see docs/architecture/security-verification-matrix.md), or list the Action in ALLOWED with the reason.');
    }

    public function test_every_allow_list_entry_still_exists_and_is_still_needed(): void
    {
        foreach (self::ALLOWED as $path => $reason) {
            $this->assertNotSame('', $reason);
            $file = base_path("app/Modules/{$path}");
            $this->assertFileExists($file, "{$path} no longer exists: remove it from ALLOWED.");
            $this->assertTrue(self::decidesOnlyBeforeTheTransaction((string) file_get_contents($file)), "{$path} now decides inside its transaction or checks no authority: remove it from ALLOWED.");
        }
    }

    public function test_the_guard_fails_on_an_action_that_checks_authority_only_before_its_transaction(): void
    {
        $bad = <<<'PHP'
            <?php
            final class BadAction
            {
                public function __invoke($actor)
                {
                    if (! $actor->can('inventory.item.manage')) {
                        throw new \RuntimeException('no');
                    }

                    return DB::transaction(function () use ($actor) {
                        // DecisionActor::require($actor, 'x', 'y') in a comment does not count
                        return 1;
                    });
                }
            }
            PHP;
        $this->assertTrue(self::decidesOnlyBeforeTheTransaction($bad));

        $decisionOutside = str_replace("return DB::transaction", "DecisionActor::require(\$actor, 'a', 'b');\n return DB::transaction", $bad);
        $this->assertTrue(self::decidesOnlyBeforeTheTransaction($decisionOutside), 'a decision before the transaction does not count');
    }

    public function test_the_guard_passes_an_action_that_decides_inside_its_transaction(): void
    {
        $good = <<<'PHP'
            <?php
            final class GoodAction
            {
                public function __invoke($actor)
                {
                    if (! $actor->can('inventory.item.manage')) {
                        throw new \RuntimeException('no');
                    }

                    return DB::transaction(function () use ($actor) {
                        $actor = DecisionActor::require($actor, 'inventory.item.manage', 'do it');

                        return 1;
                    });
                }
            }
            PHP;
        $this->assertFalse(self::decidesOnlyBeforeTheTransaction($good));
        $this->assertFalse(self::decidesOnlyBeforeTheTransaction("<?php final class Query { public function f() { return 1; } }"), 'an Action that checks no authority is not flagged');
    }
}
