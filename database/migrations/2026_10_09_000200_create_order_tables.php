<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 16);
            // Basis points for percent coupons, minor units for fixed coupons.
            $table->bigInteger('value');
            // Required for fixed coupons; a fixed coupon only applies to orders in this currency.
            $table->char('currency', 3)->nullable();
            $table->bigInteger('min_total_minor')->default(0);
            $table->integer('max_redemptions')->nullable();
            $table->integer('redemptions_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percent', 'fixed'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_check CHECK (value > 0 AND (type <> 'percent' OR value <= 10000))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_fixed_currency_check CHECK (type <> 'fixed' OR currency IS NOT NULL)");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_redemptions_check CHECK (redemptions_count >= 0 AND (max_redemptions IS NULL OR redemptions_count <= max_redemptions))');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('refunded_minor')->default(0);
            $table->char('currency', 3);
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('idempotency_key', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->unique(['buyer_id', 'idempotency_key']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'paid', 'delivered', 'cancelled', 'expired', 'partially_refunded', 'refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND total_minor >= 0 AND total_minor = subtotal_minor - discount_minor AND refunded_minor >= 0 AND refunded_minor <= total_minor)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            // Price per unit in the order currency.
            $table->bigInteger('unit_price_minor');
            $table->integer('quantity');
            // Original list price and the rate used to convert it (see docs/CURRENCY_POLICY.md).
            $table->bigInteger('list_price_minor');
            $table->char('list_currency', 3);
            $table->decimal('fx_rate', 20, 10);
            // Share of the order-level discount allocated to this line.
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->integer('commission_bps');
            // Current seller earning for this line after commission and refunds.
            $table->bigInteger('seller_earning_minor')->default(0);
            // Encrypted JSON payload: download file ids, licence keys or seller-provided text.
            $table->text('delivered_payload')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'delivered_at']);
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (unit_price_minor >= 0 AND discount_minor >= 0 AND refunded_minor >= 0 AND refunded_minor <= unit_price_minor * quantity - discount_minor)');

        Schema::table('product_license_keys', function (Blueprint $table) {
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // charge = money in; refund = money returned to the buyer.
            $table->string('kind', 16)->default('charge');
            $table->foreignId('parent_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('provider_reference', 128)->nullable();
            $table->bigInteger('amount_minor');
            $table->bigInteger('received_minor')->default(0);
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->string('crypto', 16)->nullable();
            $table->string('crypto_amount', 64)->nullable();
            $table->string('wallet_address', 255)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->jsonb('raw_payload_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->index(['order_id', 'kind']);
            $table->index(['provider', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX payments_provider_reference_unique ON payments (provider, provider_reference) WHERE provider_reference IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX payments_one_charge_per_provider ON payments (order_id, provider) WHERE kind = 'charge'");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_kind_check CHECK (kind IN ('charge', 'refund'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('shkeeper', 'balance', 'manual'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'partial', 'confirmed', 'rejected', 'failed'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_minor > 0 AND received_minor >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_refund_parent_check CHECK (kind <> 'refund' OR parent_payment_id IS NOT NULL)");

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('number', 32)->unique();
            $table->string('storage_path', 255)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::table('product_license_keys', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
        });
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
    }
};
