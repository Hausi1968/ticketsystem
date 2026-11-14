<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'assignment_mode'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['tenant_id', 'receives_assignments'])->withTimestamps();
    }

    /** Fügt ein Teammitglied hinzu; es muss Mitglied desselben Mandanten sein. */
    public function addMember(User $user, bool $receivesAssignments = true): void
    {
        $this->users()->attach($user, [
            'tenant_id' => $this->tenant_id,
            'receives_assignments' => $receivesAssignments,
        ]);
    }
}
