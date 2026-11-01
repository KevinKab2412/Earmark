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
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('direction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('direction')->default('expense')->after('category_group_id');
        });

        foreach (DB::table('categories')->get(['id', 'category_group_id']) as $category) {
            $direction = DB::table('category_groups')->where('id', $category->category_group_id)->value('direction');

            if ($direction === null) {
                throw new RuntimeException("Category [{$category->id}] has no direction-specific group.");
            }

            DB::table('categories')->where('id', $category->id)->update(['direction' => $direction]);
        }
    }
};
