<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds UNIQUE nullable indexes on moh_medicines.moh_product_id and
 * moh_medicines.moh_drug_id to enable safe upsert (key-aware import/sync).
 *
 * MySQL allows multiple NULLs inside a UNIQUE index, so records that have
 * only one key (not both) will not conflict. Each non-null key value must
 * be globally unique across the catalog.
 *
 * This migration is idempotent and safe for existing tables with data:
 * it only adds indexes; no data mutation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moh_medicines', function (Blueprint $table) {
            // Only add if they don't exist (idempotent for existing migrations)
            if (! Schema::hasIndex('moh_medicines', 'moh_medicines_moh_product_id_unique')) {
                $table->unique('moh_product_id', 'moh_medicines_moh_product_id_unique');
            }
            if (! Schema::hasIndex('moh_medicines', 'moh_medicines_moh_drug_id_unique')) {
                $table->unique('moh_drug_id', 'moh_medicines_moh_drug_id_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('moh_medicines', function (Blueprint $table) {
            $table->dropUnique('moh_medicines_moh_product_id_unique');
            $table->dropUnique('moh_medicines_moh_drug_id_unique');
        });
    }
};
