<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Models\DocumentGenerationIntent;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The trusted capability that lets bytes enter the Files store as a generated document. It is not a permission anyone holds: it exists only while the generation worker runs
 * (`GenerateDocumentArtefact`), and it is bound to one manifest, one generation intent, one worker (actor) and the fencing generation that worker claimed. The file owner
 * adapter allows the producer operations (upload, stage, verify) ONLY inside it, and even then only while the database still shows that intent RUNNING at exactly that
 * fence with a live lease on the same manifest — so a caller who merely holds the ordinary `generate` authorisation, a worker whose lease was taken over, or a request for
 * another document cannot put caller-controlled bytes into Files as a verified generated document.
 *
 * @internal for the Documents generation worker only
 */
final class DocumentGenerationScope
{
    /** @var array{manifest: int, intent: int, fence: int, actor: int}|null */
    private static ?array $active = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(int $manifestId, int $intentId, int $fence, int $actorId, Closure $work): mixed
    {
        $previous = self::$active;
        self::$active = ['manifest' => $manifestId, 'intent' => $intentId, 'fence' => $fence, 'actor' => $actorId];
        try {
            return $work();
        } finally {
            self::$active = $previous;
        }
    }

    public static function permits(int $manifestId, int $actorId): bool
    {
        $scope = self::$active;
        if ($scope === null || $scope['manifest'] !== $manifestId || $scope['actor'] !== $actorId) {
            return false;
        }
        $intent = DocumentGenerationIntent::query()->whereKey($scope['intent'])->first();

        return $intent !== null && $intent->manifest_id === $manifestId && $intent->state === 'RUNNING' && $intent->fencing_generation === $scope['fence']
            && (bool) DB::selectOne('select ?::timestamptz > clock_timestamp() as live', [$intent->getRawOriginal('lease_expiry')])->live;
    }
}
