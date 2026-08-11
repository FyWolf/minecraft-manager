<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One addon on one server.
 *
 * Records which allocation belongs to which addon, which is the whole point:
 * without it, revoking cannot tell which of a server's ports to reclaim, and
 * the capacity being sold leaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_server_addons', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->unsignedInteger('mc_addon_id');
            $table->foreign('mc_addon_id')->references('id')->on('mc_addons')->cascadeOnDelete();

            $table->string('state')->default('pending');

            // The port this addon owns. nullOnDelete rather than cascade: an
            // admin deleting an allocation by hand should not silently delete
            // the record of the addon that was using it.
            $table->unsignedInteger('allocation_id')->nullable();
            $table->foreign('allocation_id')->references('id')->on('allocations')->nullOnDelete();

            // billing | admin | self  — who authorised this.
            $table->string('source')->default('billing');

            // Billing's own identifier for the thing that pays for this, so the
            // two sides can reconcile without sharing a database.
            $table->string('billing_reference')->nullable();

            $table->string('installed_file')->nullable();
            $table->text('error')->nullable();

            // True when the port could not be written into the mod's config
            // because the mod had not generated it yet — the reconciler retries.
            $table->boolean('port_patch_pending')->default(false);

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'mc_addon_id']);
            $table->index(['server_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_server_addons');
    }
};
