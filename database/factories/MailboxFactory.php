<?php

namespace Database\Factories;

use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mailbox> */
class MailboxFactory extends Factory
{
    public function definition(): array
    {
        $email = $this->faker->unique()->userName().'@firma.de';

        return [
            'name' => 'Support',
            'email' => $email,
            'imap_host' => 'imap.example.org',
            'imap_username' => $email,
            'imap_password' => 'geheim',
            'smtp_host' => 'smtp.example.org',
            'smtp_username' => $email,
            'smtp_password' => 'geheim',
        ];
    }
}
