<?php

namespace App\Models;

use App\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantMembership extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'signature'];

    protected function casts(): array
    {
        return ['role' => MembershipRole::class];
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
