<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Financeiro": suppliers, payables and receivables split into installments.
     * A title is the document (a purchase, a bill, a sale on credit); its installments are what
     * falls due and gets settled ("baixa").
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('document', 14)->nullable(); // CPF or CNPJ, digits only
            $table->string('contact_name', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'name']);
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // payable | receivable
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'type', 'name']);
        });

        Schema::create('finance_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // payable | receivable
            $table->string('description', 160);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('counterparty', 120)->nullable(); // who pays a receivable, or a payee without registration
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('document_number', 40)->nullable();
            $table->date('issued_on');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedSmallInteger('installments_count')->default(1);
            $table->string('frequency', 12)->default('monthly'); // weekly | biweekly | monthly
            $table->boolean('auto_settle')->default(false);
            $table->string('auto_settle_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'type']);
        });

        Schema::create('finance_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('title_id')->constrained('finance_titles')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_on');
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on')->nullable();
            $table->unsignedBigInteger('paid_cents')->nullable(); // may differ from the amount (interest, discount)
            $table->string('payment_method', 20)->nullable();
            $table->boolean('settled_automatically')->default(false);
            $table->foreignId('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'due_on']);
            $table->index(['store_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_installments');
        Schema::dropIfExists('finance_titles');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('suppliers');
    }
};
