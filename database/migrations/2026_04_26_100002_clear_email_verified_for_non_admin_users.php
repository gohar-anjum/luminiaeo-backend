<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->where('is_admin', false)
            ->update(['email_verified_at' => null]);
    }

    public function down(): void {}
};
