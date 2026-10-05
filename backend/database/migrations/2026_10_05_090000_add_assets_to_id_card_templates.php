<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A design's uploaded artwork (TASK 18): the background a school brings from
 * Canva or its print shop, one per side. Stored as references to images the
 * server re-encoded on upload, and added to the stylesheet once per print
 * document rather than embedded in every card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table) {
            // {"front_background": "<ref>", "back_background": "<ref>"}
            $table->json('assets')->nullable()->after('regions');
        });
    }

    public function down(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table) {
            $table->dropColumn('assets');
        });
    }
};
