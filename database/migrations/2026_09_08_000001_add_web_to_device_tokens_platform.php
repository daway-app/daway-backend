<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * platform يقبل 'web' — لوحة الصيدلية (PWA) تسجّل توكنات FCM من المتصفح.
     * unique(user_id, device_id) يبقى كما هو — device_id ثابت لكل متصفح.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement('CREATE TABLE device_tokens_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token VARCHAR(512) NOT NULL,
                platform VARCHAR(255) NOT NULL DEFAULT \'android\' CHECK (platform IN (\'android\', \'ios\', \'web\')),
                device_id VARCHAR(191) NOT NULL,
                last_seen_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');

            DB::statement('CREATE UNIQUE INDEX device_tokens_user_device_unique_v2 ON device_tokens_new (user_id, device_id)');
            DB::statement('CREATE INDEX device_tokens_token_index_v2 ON device_tokens_new (token)');

            DB::statement('INSERT INTO device_tokens_new (id, user_id, token, platform, device_id, last_seen_at, created_at, updated_at) SELECT id, user_id, token, platform, device_id, last_seen_at, created_at, updated_at FROM device_tokens');

            DB::statement('DROP TABLE device_tokens');
            DB::statement('ALTER TABLE device_tokens_new RENAME TO device_tokens');

            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->enum('platform', ['android', 'ios', 'web'])->default('android')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement('CREATE TABLE device_tokens_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token VARCHAR(512) NOT NULL,
                platform VARCHAR(255) NOT NULL DEFAULT \'android\' CHECK (platform IN (\'android\', \'ios\')),
                device_id VARCHAR(191) NOT NULL,
                last_seen_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');

            DB::statement('CREATE UNIQUE INDEX device_tokens_user_device_unique_v2 ON device_tokens_old (user_id, device_id)');
            DB::statement('CREATE INDEX device_tokens_token_index_v2 ON device_tokens_old (token)');

            DB::statement('DELETE FROM device_tokens WHERE platform NOT IN (\'android\', \'ios\')');
            DB::statement('INSERT INTO device_tokens_old (id, user_id, token, platform, device_id, last_seen_at, created_at, updated_at) SELECT id, user_id, token, platform, device_id, last_seen_at, created_at, updated_at FROM device_tokens');

            DB::statement('DROP TABLE device_tokens');
            DB::statement('ALTER TABLE device_tokens_old RENAME TO device_tokens');

            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->enum('platform', ['android', 'ios'])->default('android')->change();
        });
    }
};

