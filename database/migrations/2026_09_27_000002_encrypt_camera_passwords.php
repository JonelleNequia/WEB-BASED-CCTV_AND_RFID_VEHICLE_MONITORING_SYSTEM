<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Camera passwords were stored as plain text. Encrypt the existing ones with
 * APP_KEY (the Camera model now casts source_password as encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        // An encrypted value is longer than 255 characters for long passwords.
        Schema::table('cameras', function (Blueprint $table): void {
            $table->text('source_password')->nullable()->change();
        });

        // "" meant "no password"; store it as NULL.
        DB::table('cameras')->where('source_password', '')->update(['source_password' => null]);

        DB::table('cameras')->whereNotNull('source_password')->where('source_password', '!=', '')
            ->get(['id', 'source_password'])
            ->each(function (object $camera): void {
                if ($this->isEncrypted($camera->source_password)) {
                    return;
                }

                DB::table('cameras')->where('id', $camera->id)
                    ->update(['source_password' => Crypt::encryptString($camera->source_password)]);
            });
    }

    public function down(): void
    {
        DB::table('cameras')->whereNotNull('source_password')->where('source_password', '!=', '')
            ->get(['id', 'source_password'])
            ->each(function (object $camera): void {
                if ($this->isEncrypted($camera->source_password)) {
                    DB::table('cameras')->where('id', $camera->id)
                        ->update(['source_password' => Crypt::decryptString($camera->source_password)]);
                }
            });
    }

    protected function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
