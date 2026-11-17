<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Enums\StatusBehaviour;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\Ticket;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Zweite Zeile der Übersicht: meine Mandanten, meine Teams und – für Supervisoren – die Mandanten,
 * in denen ich Supervisor bin, samt deren Teams. Ein Klick öffnet die Ticketliste („Alle offenen“)
 * gefiltert auf den Mandanten bzw. das Team.
 */
class MyAssignments extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.my-assignments';

    protected int|string|array $columnSpan = 'full';

    /** @return list<array{heading: string, empty: string, items: list<array{label: string, hint: ?string, url: string, open: int}>}> */
    public function getBlocks(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $user->loadMissing('memberships');

        $myTenantIds = $user->memberships->pluck('tenant_id')->all();
        $supervisedTenantIds = $user->memberships
            ->filter(fn ($m) => $m->role === MembershipRole::Supervisor)
            ->pluck('tenant_id')->all();

        $myTenants = Tenant::query()->accessible()->whereIn('id', $myTenantIds ?: [0])->orderBy('name')->get();
        $myTeams = $user->teams()->with('tenant')->orderBy('name')->get();
        $supervisedTenants = $myTenants->whereIn('id', $supervisedTenantIds)->values();
        $supervisedTeams = $supervisedTenantIds
            ? Team::query()->with('tenant')->withCount('users')->whereIn('tenant_id', $supervisedTenantIds)->orderBy('name')->get()
            : collect();

        $openByTenant = $this->openCounts('tenant_id', $myTenants->pluck('id'));
        $openByTeam = $this->openCounts('team_id', $myTeams->pluck('id')->merge($supervisedTeams->pluck('id')));

        $members = $supervisedTenantIds
            ? TenantMembership::query()->whereIn('tenant_id', $supervisedTenantIds)
                ->where('user_id', '!=', $user->getKey())
                ->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id')
            : collect();

        $tenantItem = fn (Tenant $t, ?string $hint = null) => [
            'label' => $t->name,
            'hint' => $hint,
            'color' => $t->color,
            'url' => self::ticketUrl('tenant_id', $t->id),
            'open' => (int) ($openByTenant[$t->id] ?? 0),
        ];
        $teamItem = fn (Team $t, ?string $hint = null) => [
            'label' => $t->name,
            'hint' => $hint ?? $t->tenant?->name,
            'color' => $t->tenant?->color,
            'url' => self::ticketUrl('team_id', $t->id),
            'open' => (int) ($openByTeam[$t->id] ?? 0),
        ];

        return [
            [
                'heading' => 'Meine Organisationen',
                'empty' => 'Du betreust keinen Mandanten.',
                'items' => $myTenants->map(fn (Tenant $t) => $tenantItem($t))->all(),
            ],
            [
                'heading' => 'Meine Teams',
                'empty' => 'Du bist in keinem Team.',
                'items' => $myTeams->map(fn (Team $t) => $teamItem($t))->all(),
            ],
            [
                'heading' => 'Organisationen meiner Mitarbeiter',
                'empty' => $supervisedTenantIds ? 'Keine Mandanten.' : '',
                'items' => $supervisedTenants->map(fn (Tenant $t) => $tenantItem($t,
                    self::people((int) ($members[$t->id] ?? 0))))->all(),
            ],
            [
                'heading' => 'Teams meiner Mitarbeiter',
                'empty' => $supervisedTenantIds ? 'Diese Mandanten haben noch keine Teams.' : '',
                'items' => $supervisedTeams->map(fn (Team $t) => $teamItem($t,
                    $t->tenant?->name.' · '.self::people((int) $t->users_count)))->all(),
            ],
        ];
    }

    /** Link auf die Ticketliste, Reiter „Alle offenen“, gefiltert auf Mandant bzw. Team */
    public static function ticketUrl(string $filter, int $id): string
    {
        return TicketResource::getUrl('index', [
            'tab' => 'open',
            'filters' => [$filter => ['value' => $id]],
        ]);
    }

    private function openCounts(string $column, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Ticket::query()->withBehaviour(StatusBehaviour::Open)->whereIn($column, $ids->unique()->all())
            ->selectRaw("{$column} as k, count(*) as n")->groupBy($column)->pluck('n', 'k');
    }

    private static function people(int $n): string
    {
        return $n === 1 ? '1 Mitarbeiter' : "{$n} Mitarbeiter";
    }
}
