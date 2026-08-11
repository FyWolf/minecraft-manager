<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The addon catalogue.
 *
 * An addon is a mod plus everything needed to make it actually work: which
 * loaders it runs on, whether it claims a port, and where its port lives in its
 * own config file. Seeded with a handful, editable by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_addons', function (Blueprint $table) {
            $table->increments('id');

            // Stable identifier billing refers to. Never renamed.
            $table->string('key')->unique();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();

            // Where the mod comes from.
            $table->string('provider')->default('modrinth');
            $table->string('project_id');

            // Loaders this addon supports; empty means "any".
            $table->json('loaders')->nullable();

            // The scarce resource. A port is a finite thing on a node, which is
            // the whole reason an addon can be worth charging for.
            $table->boolean('needs_port')->default(false);
            $table->string('port_protocol')->default('tcp');

            /*
             * How to write the allocated port into the mod's own config:
             *   { "format": "properties"|"line"|"yaml_section",
             *     "paths":  ["voicechat/voicechat-server.properties", ...],
             *     "key":    "port",
             *     "section":"bedrock",          // yaml_section only
             *     "stub":   "port=%PORT%\n" }   // written when the file is absent
             *
             * `paths` is a list because the same mod lands in different places
             * per loader — Geyser is under plugins/ on Paper and config/ on
             * Fabric.
             */
            $table->json('port_patch')->nullable();

            // Free addons are self-service; paid ones need billing to grant them.
            $table->boolean('free')->default(false);

            // What billing sells to enable this. Informational on this side.
            $table->string('billing_sku')->nullable();

            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_addons');
    }
};
