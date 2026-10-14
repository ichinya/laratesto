# Schema for the runnable database example

For a disposable Laravel application without project migrations, save this as
`database/migrations/2026_01_01_000000_create_skill_widgets.php`. The example's
`#[RefreshDatabase]` applies it before Laravel setup. Do not add this example
table to a real application's schema unless that is the intended work.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('skill_widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_widgets');
    }
};
```
