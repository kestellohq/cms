<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * The fleet console (app.fleet.enabled): the sites it watches, each with its fleet token
 * (sealed with the app key) and the last report it gave.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('fleet_sites', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('url', 300);
            $t->text('token');                      // sealed
            $t->string('token_last4', 4);
            $t->json('report')->nullable();         // FleetReport::normalize()
            $t->datetime('checked_at')->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamps();
            $t->uniqueIndex('url');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('fleet_sites');
    }
};
