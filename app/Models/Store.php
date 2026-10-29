<?php

namespace App\Models;

use App\Enums\AutoPrint;
use App\Enums\BillingCycle;
use App\Enums\DeliveryFeeMode;
use App\Enums\PizzaPricing;
use App\Enums\StoreRole;
use Carbon\CarbonImmutable;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $whatsapp
 * @property bool $whatsapp_enabled
 * @property string|null $whatsapp_instance
 * @property AutoPrint $auto_print
 * @property int $service_fee_percent
 * @property bool $accepts_delivery
 * @property bool $accepts_pickup
 * @property string $timezone
 * @property int $minimum_order_cents
 * @property bool $is_paused
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property int|null $plan_id
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $paid_until
 * @property bool $is_complimentary
 * @property bool $accepts_scheduling
 * @property int $scheduling_lead_minutes
 * @property int $scheduling_days_ahead
 * @property int $scheduling_slot_minutes
 * @property int|null $scheduling_slot_capacity
 * @property DeliveryFeeMode $delivery_fee_mode
 * @property int $distance_fee_per_km_cents
 * @property PizzaPricing $pizza_pricing
 * @property bool $pizza_mix_kinds
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $address_city
 * @property string|null $address_state
 * @property bool $network_enabled
 * @property int|null $network_fee_cents
 * @property BillingCycle $network_billing_cycle
 */
#[Fillable([
    'name', 'slug', 'description', 'logo_path', 'cover_path', 'primary_color', 'accent_color', 'theme', 'plan_id', 'trial_ends_at', 'paid_until', 'is_complimentary', 'whatsapp', 'whatsapp_enabled', 'whatsapp_instance', 'auto_print', 'service_fee_percent', 'email', 'timezone',
    'address_zip', 'address_street', 'address_number', 'address_district', 'address_city', 'address_state',
    'address_complement', 'latitude', 'longitude',
    'minimum_order_cents', 'is_paused', 'suspended_at', 'suspension_reason', 'accepts_delivery', 'accepts_pickup', 'accepts_dine_in', 'accepts_scheduling',
    'scheduling_lead_minutes', 'scheduling_days_ahead', 'scheduling_slot_minutes', 'scheduling_slot_capacity',
    'delivery_fee_mode', 'distance_fee_per_km_cents', 'free_delivery_radius_km', 'max_delivery_radius_km',
    'pizza_pricing', 'pizza_mix_kinds', 'network_enabled', 'network_fee_cents', 'network_billing_cycle',
])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // Every new store starts with the free trial (only matters when billing is on).
        static::creating(function (Store $store): void {
            $store->trial_ends_at ??= now()->addDays((int) config('billing.trial_days'));
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_order_cents' => 'integer',
            'is_paused' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'auto_print' => AutoPrint::class,
            'pizza_pricing' => PizzaPricing::class,
            'pizza_mix_kinds' => 'boolean',
            'service_fee_percent' => 'integer',
            'suspended_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'paid_until' => 'datetime',
            'is_complimentary' => 'boolean',
            'scheduling_lead_minutes' => 'integer',
            'scheduling_days_ahead' => 'integer',
            'scheduling_slot_minutes' => 'integer',
            'scheduling_slot_capacity' => 'integer',
            'accepts_delivery' => 'boolean',
            'accepts_pickup' => 'boolean',
            'accepts_dine_in' => 'boolean',
            'accepts_scheduling' => 'boolean',
            'delivery_fee_mode' => DeliveryFeeMode::class,
            'distance_fee_per_km_cents' => 'integer',
            'free_delivery_radius_km' => 'decimal:2',
            'max_delivery_radius_km' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
            'network_enabled' => 'boolean',
            'network_fee_cents' => 'integer',
            'network_billing_cycle' => BillingCycle::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this, StoreMember>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(StoreMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Blocked by the platform admin: no menu, no panel.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function roleOf(User $user): ?StoreRole
    {
        return StoreMember::query()
            ->where('store_id', $this->id)
            ->where('user_id', $user->id)
            ->first()
            ?->role;
    }

    /**
     * @return HasMany<OpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class);
    }

    /**
     * @return HasMany<DeliveryZone, $this>
     */
    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<OptionGroup, $this>
     */
    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<MessageTemplate, $this>
     */
    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    /**
     * @return HasMany<MessageLog, $this>
     */
    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }

    /**
     * @return HasMany<Coupon, $this>
     */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    /**
     * @return HasMany<PaymentAccount, $this>
     */
    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<NetworkDelivery, $this>
     */
    public function networkDeliveries(): HasMany
    {
        return $this->hasMany(NetworkDelivery::class);
    }

    /**
     * @return HasMany<DeliveryBill, $this>
     */
    public function deliveryBills(): HasMany
    {
        return $this->hasMany(DeliveryBill::class);
    }

    /**
     * Pinned on the map: needed to call couriers from the network.
     */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * @return HasMany<PizzaFlavorGroup, $this>
     */
    public function pizzaFlavorGroups(): HasMany
    {
        return $this->hasMany(PizzaFlavorGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PizzaFlavor, $this>
     */
    public function pizzaFlavors(): HasMany
    {
        return $this->hasMany(PizzaFlavor::class);
    }

    /**
     * @return HasMany<PizzaCrust, $this>
     */
    public function pizzaCrusts(): HasMany
    {
        return $this->hasMany(PizzaCrust::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Supplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /**
     * @return HasMany<FinanceCategory, $this>
     */
    public function financeCategories(): HasMany
    {
        return $this->hasMany(FinanceCategory::class);
    }

    /**
     * @return HasMany<FinanceTitle, $this>
     */
    public function financeTitles(): HasMany
    {
        return $this->hasMany(FinanceTitle::class);
    }

    /**
     * @return HasMany<FinanceInstallment, $this>
     */
    public function financeInstallments(): HasMany
    {
        return $this->hasMany(FinanceInstallment::class);
    }

    /**
     * @return HasMany<Banner, $this>
     */
    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class);
    }

    /**
     * @return HasMany<PrintAgent, $this>
     */
    public function printAgents(): HasMany
    {
        return $this->hasMany(PrintAgent::class);
    }

    /**
     * @return HasMany<Printer, $this>
     */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    /**
     * @return HasMany<PrintJob, $this>
     */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<CashSession, $this>
     */
    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<VoucherRedemption, $this>
     */
    public function voucherRedemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /**
     * @return HasMany<DiningTable, $this>
     */
    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    /**
     * @return HasMany<Tab, $this>
     */
    public function tabs(): HasMany
    {
        return $this->hasMany(Tab::class);
    }
}
