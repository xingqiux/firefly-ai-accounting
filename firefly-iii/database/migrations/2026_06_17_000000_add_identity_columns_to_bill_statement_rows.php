<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::table('bill_statement_rows', static function (Blueprint $table): void {
            $table->dropForeign(['duplicate_of_row_id']);
            $table->dropIndex(['user_id', 'external_key']);
            $table->dropIndex(['user_id', 'fingerprint']);
            $table->dropIndex(['bill_task_id', 'duplicate_state']);
            $table->dropColumn([
                'external_key',
                'fingerprint',
                'duplicate_of_row_id',
                'duplicate_state',
                'user_modified_at',
            ]);
        });
    }

    public function up(): void
    {
        Schema::table('bill_statement_rows', static function (Blueprint $table): void {
            $table->string('external_key')->nullable()->after('merchant_order_no');
            $table->string('fingerprint')->nullable()->after('external_key');
            $table->bigInteger('duplicate_of_row_id', false, true)->nullable()->after('fingerprint');
            $table->string('duplicate_state')->default('unique')->after('duplicate_of_row_id');
            $table->dateTime('user_modified_at')->nullable()->after('duplicate_state');

            $table->foreign('duplicate_of_row_id')->references('id')->on('bill_statement_rows')->onDelete('set null');
            $table->index(['user_id', 'external_key']);
            $table->index(['user_id', 'fingerprint']);
            $table->index(['bill_task_id', 'duplicate_state']);
        });
    }
};
