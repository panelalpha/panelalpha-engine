<?php

use App\Lib\Deploy\DeployLog\DeployLogArchive;
use App\Lib\Project\ProjectDeployState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project list reads instead of asking the host: each per-minute sample run and whether it saw every running
 * container, and each project's latest deploy verdict, mirrored from its latest.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_sample_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp')->unique();
            $table->boolean('listed');
        });

        Schema::create('project_deploy_states', function (Blueprint $table) {
            $table->string('username')->primary();
            $table->string('deploy_id')->nullable();
            $table->string('status', 16)->nullable();
            $table->unsignedBigInteger('finished_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        foreach (DeployLogArchive::users() as $row) {
            ProjectDeployState::record($row['username'], $row);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_deploy_states');
        Schema::dropIfExists('project_sample_runs');
    }
};
