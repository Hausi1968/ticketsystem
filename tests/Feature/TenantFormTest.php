<?php

use App\Models\Tenant;
use App\Models\User;

it('zeigt beim Mandanten die Auswahl des Antwort-Postfachs', function () {
    $tenant = Tenant::factory()->create();
    mailboxFor($tenant, ['email' => 'service@firma-a.de']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get("/mandanten/{$tenant->getKey()}/edit")
        ->assertOk()->assertSee('Antworten verschicken über')->assertSee('service@firma-a.de');
});
