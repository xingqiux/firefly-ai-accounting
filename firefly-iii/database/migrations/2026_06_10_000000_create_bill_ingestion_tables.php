<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('bill_secret_challenges');
        Schema::dropIfExists('bill_task_events');
        Schema::dropIfExists('bill_artifacts');
        Schema::dropIfExists('bill_tasks');
        Schema::dropIfExists('bill_mail_messages');
    }

    public function up(): void
    {
        Schema::create('bill_mail_messages', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('user_id', false, true);
            $table->string('message_id')->nullable();
            $table->string('mailbox')->nullable();
            $table->string('from_address')->nullable();
            $table->string('to_address')->nullable();
            $table->string('subject')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->string('raw_path')->nullable();
            $table->string('body_text_path')->nullable();
            $table->string('body_html_path')->nullable();
            $table->string('checksum')->nullable();
            $table->string('sync_cursor')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'received_at']);
            $table->unique(['user_id', 'message_id']);
        });

        Schema::create('bill_tasks', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('user_id', false, true);
            $table->bigInteger('bill_mail_message_id', false, true)->nullable();
            $table->string('source')->default('unknown');
            $table->string('profile_id')->nullable();
            $table->string('status')->default('received');
            $table->dateTime('received_at')->nullable();
            $table->string('summary')->nullable();
            $table->bigInteger('current_secret_challenge_id', false, true)->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('bill_mail_message_id')->references('id')->on('bill_mail_messages')->onDelete('set null');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'received_at']);
        });

        Schema::create('bill_artifacts', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('bill_task_id', false, true);
            $table->bigInteger('derived_from_artifact_id', false, true)->nullable();
            $table->string('kind');
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('checksum')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->json('metadata')->nullable();

            $table->foreign('bill_task_id')->references('id')->on('bill_tasks')->onDelete('cascade');
            $table->foreign('derived_from_artifact_id')->references('id')->on('bill_artifacts')->onDelete('set null');
            $table->index(['bill_task_id', 'kind']);
        });

        Schema::create('bill_task_events', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('bill_task_id', false, true);
            $table->string('event_type');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreign('bill_task_id')->references('id')->on('bill_tasks')->onDelete('cascade');
            $table->index(['bill_task_id', 'created_at']);
        });

        Schema::create('bill_secret_challenges', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('bill_task_id', false, true);
            $table->string('kind')->default('password');
            $table->string('prompt')->nullable();
            $table->string('status')->default('open');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('consumed_at')->nullable();

            $table->foreign('bill_task_id')->references('id')->on('bill_tasks')->onDelete('cascade');
            $table->index(['bill_task_id', 'status']);
        });
    }
};
