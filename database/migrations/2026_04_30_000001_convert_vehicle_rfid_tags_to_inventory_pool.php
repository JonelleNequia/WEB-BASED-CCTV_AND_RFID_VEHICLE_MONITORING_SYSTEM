<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $addedUidColumn = false;

        if (! Schema::hasColumn('vehicle_rfid_tags', 'uid')) {
            Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
                $table->string('uid', 100)->nullable()->after('id');
            });

            $addedUidColumn = true;
        }

        DB::table('vehicle_rfid_tags')
            ->whereNull('uid')
            ->whereNotNull('tag_uid')
            ->update(['uid' => DB::raw('tag_uid')]);

        DB::table('vehicle_rfid_tags')
            ->where('status', 'active')
            ->update(['status' => 'assigned']);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildVehicleRfidTagsForSqlite();
        } else {
            Schema::table('vehicle_rfid_tags', function (Blueprint $table) use ($addedUidColumn): void {
                $table->foreignId('vehicle_id')->nullable()->change();

                if ($addedUidColumn) {
                    $table->unique('uid');
                }
            });
        }

        if (! Schema::hasColumn('vehicles', 'rfid_tag_id')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->foreignId('rfid_tag_id')
                    ->nullable()
                    ->after('rfid_tag_uid')
                    ->constrained('vehicle_rfid_tags')
                    ->nullOnDelete();
            });
        }

        DB::table('vehicles')
            ->whereNotNull('rfid_tag_uid')
            ->orderBy('id')
            ->select(['id', 'rfid_tag_uid'])
            ->chunkById(100, function ($vehicles): void {
                foreach ($vehicles as $vehicle) {
                    $tag = DB::table('vehicle_rfid_tags')
                        ->where('uid', $vehicle->rfid_tag_uid)
                        ->orWhere('tag_uid', $vehicle->rfid_tag_uid)
                        ->first();

                    if (! $tag) {
                        continue;
                    }

                    DB::table('vehicles')
                        ->where('id', $vehicle->id)
                        ->update(['rfid_tag_id' => $tag->id]);

                    DB::table('vehicle_rfid_tags')
                        ->where('id', $tag->id)
                        ->update([
                            'vehicle_id' => $vehicle->id,
                            'status' => $tag->status === 'inactive' ? 'inactive' : 'assigned',
                            'assigned_at' => $tag->assigned_at ?? now(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('vehicles', 'rfid_tag_id')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('rfid_tag_id');
            });
        }

        if (Schema::hasColumn('vehicle_rfid_tags', 'uid')) {
            Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
                $table->dropColumn('uid');
            });
        }
    }

    protected function rebuildVehicleRfidTagsForSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement('DROP TABLE IF EXISTS vehicle_rfid_tags_inventory_temp');
        DB::statement(<<<'SQL'
            CREATE TABLE vehicle_rfid_tags_inventory_temp (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                vehicle_id INTEGER NULL,
                uid VARCHAR(100) NULL,
                tag_uid VARCHAR(100) NULL,
                status VARCHAR(255) NOT NULL DEFAULT 'available',
                assigned_at DATETIME NULL,
                last_scanned_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
            )
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO vehicle_rfid_tags_inventory_temp (
                id,
                vehicle_id,
                uid,
                tag_uid,
                status,
                assigned_at,
                last_scanned_at,
                created_at,
                updated_at
            )
            SELECT
                id,
                vehicle_id,
                COALESCE(uid, tag_uid),
                COALESCE(tag_uid, uid),
                CASE WHEN status = 'active' THEN 'assigned' ELSE status END,
                assigned_at,
                last_scanned_at,
                created_at,
                updated_at
            FROM vehicle_rfid_tags
        SQL);
        DB::statement('DROP TABLE vehicle_rfid_tags');
        DB::statement('ALTER TABLE vehicle_rfid_tags_inventory_temp RENAME TO vehicle_rfid_tags');
        DB::statement('CREATE UNIQUE INDEX vehicle_rfid_tags_uid_unique ON vehicle_rfid_tags (uid)');
        DB::statement('CREATE UNIQUE INDEX vehicle_rfid_tags_tag_uid_unique ON vehicle_rfid_tags (tag_uid)');
        DB::statement('CREATE INDEX vehicle_rfid_tags_status_index ON vehicle_rfid_tags (status)');
        DB::statement('CREATE INDEX vehicle_rfid_tags_vehicle_id_index ON vehicle_rfid_tags (vehicle_id)');
        DB::statement('PRAGMA foreign_keys=ON');
    }
};
