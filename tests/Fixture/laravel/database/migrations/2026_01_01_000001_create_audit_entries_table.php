<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', static function (Blueprint $table): void {
            $table->id();
            $table->string('message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
