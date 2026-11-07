<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Which theme file a site change
 * was based on (so a theme update can be compared and merged), and fonts
 * downloaded once and served from this site.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->table('theme_documents', function (Blueprint $t): void {
            $t->string('base_hash', 64)->nullable();     // sha256 of the theme file the change started from
        });
        $this->schema()->create('theme_fonts', function (Blueprint $t): void {
            $t->id();
            $t->string('slug', 64)->unique();            // "gf-inter"
            $t->string('family', 100);
            $t->string('category', 20)->default('sans-serif');
            $t->string('source', 20)->default('google');
            $t->text('files');                           // JSON: [{weight, style, file, range}]
            $t->bigInteger('bytes')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('theme_fonts');
        $this->schema()->table('theme_documents', function (Blueprint $t): void {
            $t->dropColumn('base_hash');
        });
    }
};
