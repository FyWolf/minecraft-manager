<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_pack_installs', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            // Who asked for it. A queued job has no auth context, so the actor
            // has to be recorded here and set explicitly on the activity entry.
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('provider');
            $table->string('project_id');
            $table->string('version_id');
            $table->string('pack_name');
            $table->string('pack_version')->nullable();

            $table->string('loader')->nullable();
            $table->string('loader_version')->nullable();
            $table->string('mc_version')->nullable();

            $table->string('state')->default('queued');
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->string('current_step')->nullable();

            $table->text('error')->nullable();

            // Per-file outcomes: [{path, status: ok|manual|failed, reason?}].
            // A JSON column rather than a second table — a 300-mod pack is one
            // ~40 KB blob and nothing ever queries per-file outcomes
            // relationally, so a join table would cost a migration and a model
            // and buy nothing.
            $table->json('log')->nullable();

            $table->string('backup_path')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Drives the concurrency guard: "is anything non-terminal running
            // for this server?"
            $table->index(['server_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_pack_installs');
    }
};
