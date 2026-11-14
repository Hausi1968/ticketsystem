<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Für alle Modelle mit Spalte tenant_id.
 *
 * - Lesen: nur Datensätze der Mandanten, auf die der aktuelle Kontext Zugriff hat.
 * - Anlegen/Ändern: nur in einem erlaubten Mandanten; der Mandant eines Datensatzes
 *   kann nachträglich nicht geändert werden.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id)) {
                $model->tenant_id = $context->defaultTenantId();
            }

            if (empty($model->tenant_id)) {
                throw new \LogicException(static::class.' kann nicht ohne Mandant angelegt werden.');
            }

            if (! $context->canAccess((int) $model->tenant_id)) {
                throw new AuthorizationException('Kein Zugriff auf diesen Mandanten.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new \LogicException('Der Mandant eines Datensatzes kann nicht geändert werden.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
