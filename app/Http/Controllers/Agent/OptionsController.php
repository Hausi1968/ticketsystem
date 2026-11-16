<?php

namespace App\Http\Controllers\Agent;

use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\TicketTabs;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Auswahllisten für die App: Reiter, Status, Prioritäten, Kategorien, Mandanten und Bearbeiter */
class OptionsController
{
    public function __invoke(): JsonResponse
    {
        $accessible = app(TenantContext::class)->accessibleTenantIds();
        $tenants = Tenant::query()->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible))
            ->orderBy('name')->get(['id', 'name', 'short_code', 'color']);
        $tenantIds = $tenants->pluck('id')->all();

        $members = DB::table('tenant_user')->whereIn('tenant_id', $tenantIds)
            ->where('role', '!=', MembershipRole::LightAgent->value)->get(['user_id', 'tenant_id'])->groupBy('user_id');

        return response()->json([
            'tabs' => collect(TicketTabs::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'statuses' => TicketStatus::query()->where('active', true)->ordered()->get()->map(fn (TicketStatus $s) => [
                'id' => $s->getKey(), 'name' => $s->name, 'color' => $s->color, 'behaviour' => $s->behaviour?->value, 'closes' => $s->closes(),
            ]),
            'priorities' => collect(TicketPriority::cases())->map(fn (TicketPriority $p) => ['value' => $p->value, 'label' => $p->getLabel()]),
            'categories' => TicketCategory::query()->where('active', true)->ordered()->get()->map(fn (TicketCategory $c) => [
                'id' => $c->getKey(), 'name' => $c->name, 'tenant_id' => $c->tenant_id,
            ]),
            'tenants' => $tenants,
            'teams' => Team::query()->whereIn('tenant_id', $tenantIds)->orderBy('name')->get(['id', 'name', 'tenant_id'])
                ->map(fn (Team $t) => ['id' => $t->getKey(), 'name' => $t->name, 'tenant_id' => (int) $t->tenant_id])->values(),
            'users' => User::query()->where('active', true)->orderBy('name')->get()
                ->filter(fn (User $u) => $u->is_admin || $members->has($u->getKey()))
                ->map(fn (User $u) => [
                    'id' => $u->getKey(),
                    'name' => $u->name,
                    'tenant_ids' => $u->is_admin ? $tenantIds : $members->get($u->getKey())->pluck('tenant_id')->map(fn ($id) => (int) $id)->values(),
                ])->values(),
        ]);
    }
}
