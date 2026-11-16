<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Security\SecurityLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class TenantMembership extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'signature'];

    protected function casts(): array
    {
        return ['role' => MembershipRole::class];
    }

    protected static function booted(): void
    {
        $log = fn (string $what) => fn (TenantMembership $m) => SecurityLog::record('membership_changed', User::query()->find($m->user_id), [
            'aenderung' => $what,
            'mandant' => DB::table('tenants')->where('id', $m->tenant_id)->value('short_code'),
            'rolle' => $m->role instanceof MembershipRole ? $m->role->value : $m->role,
        ]);

        static::created($log('hinzugefügt'));
        static::updated(fn (TenantMembership $m) => $m->wasChanged('role') ? $log('Rolle geändert')($m) : null);
        static::deleted($log('entfernt'));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
