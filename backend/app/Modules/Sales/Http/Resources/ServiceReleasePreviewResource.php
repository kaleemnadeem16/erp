<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Review of one stage's release. Withholds source pins, family answers, lineage references, the policy version and any evidence text. */
final class ServiceReleasePreviewResource extends JsonResource
{
    /** @return array{ready:bool,blockers:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>,cost:array{required:bool,status:string},applicability:array{status:string,materials:string,subcontract:string},basis_id:int|null,basis_version:int|null,fingerprint:string} */
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $d */
        $d = $this->resource;

        return [
            'ready' => (bool) $d['ready'],
            'blockers' => array_values($d['blockers']),
            'next_actions' => array_map(fn (array $a): array => ['fact' => (string) $a['fact'], 'owner' => (string) $a['owner'], 'action' => (string) $a['action']], array_values($d['next_actions'])),
            'cost' => ['required' => (bool) $d['cost']['required'], 'status' => (string) $d['cost']['status']],
            'applicability' => ['status' => (string) $d['applicability']['status'], 'materials' => (string) $d['applicability']['scope']['materials'], 'subcontract' => (string) $d['applicability']['scope']['subcontract']],
            'basis_id' => $d['basis_id'],
            'basis_version' => $d['basis_version'],
            'fingerprint' => (string) $d['fingerprint'],
        ];
    }
}
