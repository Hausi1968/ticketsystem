<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Bestimmt, auf welche Mandanten die aktuelle Anfrage (oder der aktuelle Job) zugreifen darf.
 *
 * Grundsatz "fail closed": Ohne angemeldeten Benutzer und ohne ausdrücklich gesetzten
 * Mandanten sind keine mandantenbezogenen Daten sichtbar.
 */
class TenantContext
{
    /** @var list<int>|null */
    private ?array $override = null;

    private bool $bypass = false;

    /** @var array<int, list<int>> */
    private array $membershipCache = [];

    /**
     * @return list<int>|null null = alle Mandanten (Admin oder ausdrücklich ohne Mandantentrennung)
     */
    public function accessibleTenantIds(): ?array
    {
        if ($this->bypass) {
            return null;
        }

        if ($this->override !== null) {
            return $this->override;
        }

        $user = Auth::user();

        if (! $user instanceof User || ! $user->active) {
            return [];
        }

        if ($user->is_admin) {
            return null;
        }

        return $this->membershipCache[$user->getKey()] ??= DB::table('tenant_user')
            ->where('user_id', $user->getKey())
            ->pluck('tenant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canAccess(int $tenantId): bool
    {
        $ids = $this->accessibleTenantIds();

        return $ids === null || in_array($tenantId, $ids, true);
    }

    /** Mandant, der beim Anlegen automatisch gesetzt wird (nur wenn eindeutig). */
    public function defaultTenantId(): ?int
    {
        $ids = $this->accessibleTenantIds();

        return ($ids !== null && count($ids) === 1) ? $ids[0] : null;
    }

    /**
     * Führt Code im Kontext genau eines Mandanten aus, z. B. in Jobs (Mailabruf eines Postfachs).
     */
    public function runFor(Tenant|int $tenant, callable $callback): mixed
    {
        $previous = [$this->override, $this->bypass];
        $this->override = [$tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant];
        $this->bypass = false;

        try {
            return $callback();
        } finally {
            [$this->override, $this->bypass] = $previous;
        }
    }

    /**
     * Ausdrücklich ohne Mandantentrennung, nur für Plattform-Aufgaben (z. B. Löschfristen über alle Mandanten).
     */
    public function runWithoutTenancy(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }

    public function flush(): void
    {
        $this->membershipCache = [];
    }
}
