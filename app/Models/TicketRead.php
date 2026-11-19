<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * „Mitarbeiter X hat Ticket Y zuletzt um … angesehen“. Grundlage für die Markierung „NEU“,
 * wenn danach eine Kundenantwort eingeht. Wird nur über das (mandantengeprüfte) Ticket abgefragt.
 */
class TicketRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
