<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking multi-canal — atribución first-touch:
 * UTMs estándar + click IDs de las principales redes de ads + landing/referrer.
 * Todo nullable — las columnas se pueblan desde web forms, API y referral de WhatsApp
 * la primera vez que el lead recibe datos de origen; luego no se sobreescriben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('utm_source', 120)->nullable()->after('source_url');
            $table->string('utm_medium', 120)->nullable()->after('utm_source');
            $table->string('utm_campaign', 191)->nullable()->after('utm_medium');
            $table->string('utm_content', 191)->nullable()->after('utm_campaign');
            $table->string('utm_term', 191)->nullable()->after('utm_content');

            $table->string('gclid', 191)->nullable()->after('utm_term');   // Google Ads
            $table->string('fbclid', 191)->nullable()->after('gclid');     // Meta
            $table->string('ttclid', 191)->nullable()->after('fbclid');    // TikTok
            $table->string('msclkid', 191)->nullable()->after('ttclid');   // Bing / Microsoft Ads

            $table->string('landing_url', 2048)->nullable()->after('msclkid');
            $table->string('referrer_url', 2048)->nullable()->after('landing_url');
            $table->timestamp('first_touch_at')->nullable()->after('referrer_url');

            $table->index(['account_id', 'utm_source']);
            $table->index(['account_id', 'utm_campaign']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'utm_source']);
            $table->dropIndex(['account_id', 'utm_campaign']);
            $table->dropColumn([
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                'gclid', 'fbclid', 'ttclid', 'msclkid',
                'landing_url', 'referrer_url', 'first_touch_at',
            ]);
        });
    }
};
