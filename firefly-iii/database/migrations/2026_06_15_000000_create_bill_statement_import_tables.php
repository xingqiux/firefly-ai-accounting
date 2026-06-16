<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('bill_statement_rows');
        Schema::dropIfExists('bill_statement_imports');
    }

    public function up(): void
    {
        Schema::create('bill_statement_imports', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('user_id', false, true);
            $table->bigInteger('bill_task_id', false, true);
            $table->bigInteger('bill_artifact_id', false, true);
            $table->string('source');
            $table->string('profile_id')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('archived_filename');
            $table->dateTime('exported_at')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('status')->default('parsed');
            $table->json('metadata')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('bill_task_id')->references('id')->on('bill_tasks')->onDelete('cascade');
            $table->foreign('bill_artifact_id')->references('id')->on('bill_artifacts')->onDelete('cascade');
            $table->unique('bill_artifact_id');
            $table->index(['user_id', 'source']);
            $table->index(['bill_task_id', 'status']);
        });

        Schema::create('bill_statement_rows', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->bigInteger('user_id', false, true);
            $table->bigInteger('bill_task_id', false, true);
            $table->bigInteger('bill_statement_import_id', false, true);
            $table->unsignedInteger('row_number');
            $table->string('status')->default('pending');
            $table->dateTime('occurred_at')->nullable();
            $table->string('platform_category')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('counterparty_account')->nullable();
            $table->text('description')->nullable();
            $table->string('direction')->nullable();
            $table->decimal('amount', 36, 18)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_status')->nullable();
            $table->string('platform_order_no')->nullable();
            $table->string('merchant_order_no')->nullable();
            $table->text('remark')->nullable();
            $table->json('raw_data');
            $table->json('editable_data');
            $table->string('firefly_type')->nullable();
            $table->dateTime('firefly_date')->nullable();
            $table->decimal('firefly_amount', 36, 18)->nullable();
            $table->text('firefly_description')->nullable();
            $table->string('source_name')->nullable();
            $table->string('destination_name')->nullable();
            $table->string('category_name')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->bigInteger('transaction_group_id', false, true)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('bill_task_id')->references('id')->on('bill_tasks')->onDelete('cascade');
            $table->foreign('bill_statement_import_id')->references('id')->on('bill_statement_imports')->onDelete('cascade');
            $table->foreign('transaction_group_id')->references('id')->on('transaction_groups')->onDelete('set null');
            $table->unique(['bill_statement_import_id', 'row_number']);
            $table->index(['bill_task_id', 'status']);
            $table->index(['user_id', 'occurred_at']);
        });
    }
};
