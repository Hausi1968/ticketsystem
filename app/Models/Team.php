<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Mail\Outbound\TeamSignature;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Team extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'assignment_mode', 'notify_on_assignment',
        'signature_html', 'signature_logo_path', 'signature_logo_width'];

    protected $casts = ['notify_on_assignment' => 'boolean', 'signature_logo_width' => 'integer'];

    protected static function booted(): void
    {
        // Signatur-HTML wird nur bereinigt gespeichert; ein ersetztes Logo wird gelöscht
        static::saving(function (Team $team) {
            if ($team->isDirty('signature_html')) {
                $team->signature_html = TeamSignature::clean($team->signature_html);
            }
            $old = $team->getOriginal('signature_logo_path');
            if ($team->isDirty('signature_logo_path') && $old && $old !== $team->signature_logo_path) {
                Storage::disk('local')->delete($old);
            }
        });
        static::deleted(fn (Team $team) => $team->signature_logo_path && Storage::disk('local')->delete($team->signature_logo_path));
    }

    public const MANUAL = 'manual';

    public const ROUND_ROBIN = 'round_robin';

    public const LOAD = 'load';

    public const ASSIGNMENT_MODES = [
        self::MANUAL => 'Manuell / Übernehmen',
        self::ROUND_ROBIN => 'Automatisch reihum',
        self::LOAD => 'Automatisch nach Auslastung (wer am wenigsten offen hat)',
    ];

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

    /**
     * Mitglieder, die automatisch Tickets bekommen können: „bekommt Zuweisungen“, aktiv und im Mandanten
     * mindestens Agent (Light-Agents dürfen nicht antworten).
     */
    public function assignableMembers()
    {
        return $this->users()->wherePivot('receives_assignments', true)
            ->where('users.active', true)
            ->where(fn ($q) => $q->where('users.is_admin', true)
                ->orWhereHas('memberships', fn ($m) => $m->where('tenant_id', $this->tenant_id)
                    ->where('role', '!=', MembershipRole::LightAgent->value)));
    }

    /** Nächster Bearbeiter nach der Zuweisungsart des Teams; null bei „Manuell“ oder ohne passende Mitglieder */
    public function nextAssignee(): ?User
    {
        return match ($this->assignment_mode) {
            self::ROUND_ROBIN => $this->nextInRotation(),
            self::LOAD => $this->leastBusy(),
            default => null,
        };
    }

    /** Reihum: das nächste Mitglied nach dem zuletzt bedachten (nach Kennung sortiert), am Ende wieder von vorn */
    private function nextInRotation(): ?User
    {
        $members = $this->assignableMembers()->orderBy('users.id')->get();
        if ($members->isEmpty()) {
            return null;
        }

        $next = $members->first(fn (User $u) => $u->getKey() > (int) $this->last_assigned_user_id) ?? $members->first();
        $this->forceFill(['last_assigned_user_id' => $next->getKey()])->save();

        return $next;
    }

    /** Nach Auslastung: das Mitglied mit den wenigsten offenen oder wartenden Tickets dieses Mandanten */
    public function leastBusy(): ?User
    {
        return $this->assignableMembers()
            ->withCount(['assignedTickets as open_count' => fn ($q) => $q->where('tenant_id', $this->tenant_id)->unresolved()])
            ->orderBy('open_count')->orderBy('users.id')->first();
    }
}
