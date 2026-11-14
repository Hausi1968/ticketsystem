<?php

namespace App\Console\Commands;

use App\Models\ApiUpload;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Räumt täglich auf: nicht verwendete API-Uploads (älter als 24 h) und alte Idempotenz-Schlüssel. */
class Cleanup extends Command
{
    protected $signature = 'anliego:cleanup';

    protected $description = 'Löscht verwaiste Uploads und abgelaufene Idempotenz-Schlüssel';

    public function handle(TenantContext $tenancy): int
    {
        $deleted = $tenancy->runWithoutTenancy(function () {
            $count = 0;
            ApiUpload::query()->whereNull('used_at')->where('created_at', '<', now()->subDay())
                ->each(function (ApiUpload $upload) use (&$count) {
                    Storage::disk('local')->delete($upload->path);
                    $upload->delete();
                    $count++;
                });

            return $count;
        });

        $keys = DB::table('api_idempotency_keys')->where('created_at', '<', now()->subDays(7))->delete();

        $this->line("{$deleted} Upload(s) und {$keys} Idempotenz-Schlüssel gelöscht.");

        return self::SUCCESS;
    }
}
