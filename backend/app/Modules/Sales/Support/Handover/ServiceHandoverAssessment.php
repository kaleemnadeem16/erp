<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Support\CurrentCompany;
use App\Support\Status;

/** @internal Fresh, locked operational facts. Missing release contracts remain UNKNOWN, never waived by a service Item discriminator. */
final class ServiceHandoverAssessment
{
    public function __construct(private readonly HandoverSourceRegistry $sources) {}

    /**
     * @param  array<string,mixed>  $inputs
     * @return array{assessment:array<string,mixed>,operational:array<string,mixed>,fingerprint:string,valid_references:bool}
     */
    public function assess(HandoverSubject $s, array $inputs, bool $preparing = false): array
    {
        if ($preparing) {
            $this->sources->requirePreparation($s->line->source_kind, $inputs['path']);
        } elseif ($s->line->source_kind === 'DIRECT_ITEM') {
            return ['assessment' => ['acceptance_id' => $s->acceptance->id, 'assessment_version' => HandoverSourceRegistry::VERSION, 'path' => $inputs['path'], 'status' => 'NOT_READY', 'preparation_ready' => false,
                'missing' => ['HANDOVER_PATH_UNSUPPORTED'], 'next_actions' => [['fact' => 'GOODS_ORDER_TARGET', 'owner' => 'Sales', 'action' => 'A separately delivered goods Order adapter is required.']]],
                'operational' => [], 'fingerprint' => '', 'valid_references' => false];
        } else {
            $this->sources->requirePreparation($s->line->source_kind, $inputs['path']);
        }
        $item = Item::withTrashed()->sharedLock()->find($s->line->item_id);
        $contact = $inputs['contact_id'] === null ? null : PartyContact::withTrashed()->where('party_id', $s->case->party_id)->sharedLock()->find($inputs['contact_id']);
        $site = $inputs['site_id'] === null ? null : PartySite::withTrashed()->where('party_id', $s->case->party_id)->sharedLock()->find($inputs['site_id']);
        $itemValid = $item !== null && $item->type === ItemType::Service && ! $item->is_stocked && $item->status === Status::Active && ! $item->trashed();
        $contactValid = $contact !== null && ! $contact->trashed() && (trim((string) $contact->email) !== '' || trim((string) $contact->phone) !== '');
        $siteValid = $site !== null && ! $site->trashed() && $site->status === Status::Active;
        $missing = [];
        $next = [];
        $add = function (string $fact, string $owner, string $action) use (&$missing, &$next): void {
            $missing[] = $fact;
            $next[] = ['fact' => $fact, 'owner' => $owner, 'action' => $action];
        };
        if (! $itemValid) {
            $add('SERVICE_SOURCE_NOT_AVAILABLE', 'Inventory', 'Restore an active non-stock service Item, or resolve the source change under governed acceptance correction.');
        }
        if ($inputs['location_kind'] === 'UNKNOWN') {
            $add('SERVICE_LOCATION_REQUIRED', 'Sales', 'Specify remote or on-site delivery without changing agreed commercial scope.');
        }
        if (! $contactValid) {
            $add('SERVICE_CONTACT_REQUIRED', 'Parties', 'Select a current contact of this customer with an email address or telephone.');
        }
        if ($inputs['location_kind'] === 'ON_SITE' && ! $siteValid) {
            $add('SERVICE_SITE_REQUIRED', 'Parties', 'Select a current active site belonging to this customer.');
        }
        $preparationReady = $missing === [];
        $add('COMPLETION_CONDITIONS_NOT_DELIVERED', 'Sales', 'Supply canonical versioned, customer-agreed completion conditions through the source contract; do not invent them in the plan.');
        $add('SERVICE_COST_CERTAINTY_UNKNOWN', 'Sales', 'Deliver source-owned cost-certainty evidence; unknown cost is not zero cost.');
        $add('INVENTORY_APPLICABILITY_UNVERIFIED', 'Inventory', 'Verify material/warehouse applicability through the owning contract; service type alone is not proof of no inventory effects.');
        $add('PURCHASING_APPLICABILITY_UNVERIFIED', 'Purchasing', 'Verify supplier/subcontracting applicability through the owning contract.');
        $add('SERVICE_JOB_ADAPTER_NOT_DELIVERED', 'Jobs', 'Deliver and verify the release receiver in separately authorised slice 4.');
        $operational = ['deliverable_identity' => 'issued-scope:'.$s->line->logical_scope_id, 'description' => $s->line->description,
            'measure_id' => null, 'quantity' => null, 'completion_conditions' => null, 'location_kind' => $inputs['location_kind'], 'instructions' => $inputs['instructions'],
            'contact' => $contactValid ? ['id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email, 'phone' => $contact->phone] : null,
            'site' => $siteValid ? ['id' => $site->id, 'name' => $site->name, 'address_line1' => $site->address_line1, 'city' => $site->city, 'country_code' => $site->country_code] : null];
        $assessment = ['acceptance_id' => $s->acceptance->id, 'assessment_version' => HandoverSourceRegistry::VERSION, 'path' => $inputs['path'], 'status' => 'NOT_READY',
            'preparation_ready' => $preparationReady, 'missing' => $missing, 'next_actions' => $next];
        $fingerprint = OperationIntent::hash('handover.review', ['acceptance' => $s->acceptance->id, 'accepted_intent' => $s->acceptance->intent_digest,
            'issued_line' => $s->line->getAttributes(), 'inputs' => $inputs, 'operational' => $operational, 'assessment' => $assessment,
            'item_state' => $item?->only(['id', 'type', 'status', 'is_stocked', 'deleted_at', 'updated_at']),
            'contact_state' => $contact?->only(['id', 'name', 'email', 'phone', 'deleted_at', 'updated_at']),
            'site_state' => $site?->only(['id', 'status', 'name', 'address_line1', 'city', 'country_code', 'deleted_at', 'updated_at']),
            'company_day' => now(app(CurrentCompany::class)->timezone())->toDateString()]);

        return ['assessment' => $assessment, 'operational' => $operational, 'fingerprint' => $fingerprint,
            'valid_references' => $itemValid && ($inputs['contact_id'] === null || $contactValid) && ($inputs['site_id'] === null || $siteValid)];
    }
}
