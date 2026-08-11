<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_capability_profiles', function (Blueprint $table) {
            // increments(), not id() — matches the eggs table and the
            // egg_game_query precedent, so the pivot's foreign key is a plain
            // unsignedInteger on both sides.
            $table->increments('id');

            $table->string('name')->unique();
            $table->string('loader')->nullable();

            // Which pages this egg gets. Array of Capability values.
            $table->json('capabilities');

            // Where mods/plugins install. Null means this profile has no
            // content browser at all (Vanilla).
            $table->string('content_dir')->nullable();

            // Null means "no worlds" (a proxy).
            $table->string('worlds_dir')->nullable()->default('/');

            // `bukkit`  => world_nether / world_the_end are sibling folders
            // `vanilla` => dimensions nest inside world/DIM-1
            $table->string('dimension_layout')->default('vanilla');

            // Null means this loader ships an installer rather than a runnable
            // jar, so version changes take the reinstall path.
            $table->string('version_provider')->nullable();
            $table->string('jar_path')->nullable();

            // Ordered candidate env_variable names; the first that exists on the
            // server wins. Eggs disagree (MINECRAFT_VERSION vs MC_VERSION vs
            // VERSION) and we must not guess.
            $table->json('mc_version_variables')->nullable();
            $table->json('loader_version_variables')->nullable();

            $table->json('config_files')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_capability_profiles');
    }
};
