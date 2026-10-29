<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BillingAdminController;
use App\Http\Controllers\Admin\CourierAdminController;
use App\Http\Controllers\Admin\InvoiceAdminController;
use App\Http\Controllers\Admin\NetworkAdminController;
use App\Http\Controllers\Admin\NetworkFinanceAdminController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\OptionGroupController;
use App\Http\Controllers\Catalog\PizzaController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Courier\CourierAppController;
use App\Http\Controllers\Courier\CourierManifestController;
use App\Http\Controllers\Courier\CourierPushController;
use App\Http\Controllers\Courier\CourierRegistrationController;
use App\Http\Controllers\Courier\CourierRunController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Delivery\CourierMapController;
use App\Http\Controllers\Delivery\MyDeliveriesController;
use App\Http\Controllers\Finance\CategoryController as FinanceCategoryController;
use App\Http\Controllers\Finance\InstallmentController;
use App\Http\Controllers\Finance\OverviewController as FinanceOverviewController;
use App\Http\Controllers\Finance\SupplierController;
use App\Http\Controllers\Finance\TitleController;
use App\Http\Controllers\Marketing\BannerController;
use App\Http\Controllers\Marketing\CouponController;
use App\Http\Controllers\Orders\NetworkDeliveryController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\OrderEditController;
use App\Http\Controllers\Orders\PendingOrdersController;
use App\Http\Controllers\Pos\CashController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\PublicMenuController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreSettings\AppearanceController;
use App\Http\Controllers\StoreSettings\ContactController;
use App\Http\Controllers\StoreSettings\CourierNetworkController;
use App\Http\Controllers\StoreSettings\DeliveryController;
use App\Http\Controllers\StoreSettings\HoursController;
use App\Http\Controllers\StoreSettings\MessagesController;
use App\Http\Controllers\StoreSettings\OnlinePaymentsController;
use App\Http\Controllers\StoreSettings\PaymentsController;
use App\Http\Controllers\StoreSettings\PrintingController;
use App\Http\Controllers\StoreSettings\TablesController;
use App\Http\Controllers\StoreSettings\TeamController;
use App\Http\Controllers\StoreSettings\WhatsAppConnectionController;
use App\Http\Controllers\StoreStatusController;
use App\Http\Controllers\Tables\TableController;
use App\Http\Middleware\EnsureMenuAvailable;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome', [
    'contactWhatsapp' => config('billing.contact_whatsapp'),
])->name('home');

// Courier network app, on the courier's phone. Signing up is open; the rest needs a registration.
Route::prefix('entregador')->name('courier.')->group(function () {
    Route::get('manifest.webmanifest', CourierManifestController::class)->name('manifest');
    Route::get('cadastro', [CourierRegistrationController::class, 'create'])->name('register');
    Route::post('cadastro', [CourierRegistrationController::class, 'store'])->middleware('throttle:6,1')->name('register.store');

    Route::middleware(['auth', 'courier'])->group(function () {
        Route::get('/', [CourierAppController::class, 'home'])->name('home');
        Route::post('online', [CourierAppController::class, 'online'])->name('online');
        Route::post('location', [CourierAppController::class, 'location'])->middleware('throttle:60,1')->name('location');
        Route::post('push', [CourierPushController::class, 'store'])->middleware('throttle:20,1')->name('push.store');
        Route::get('ganhos', [CourierAppController::class, 'earnings'])->name('earnings');
        Route::get('perfil', [CourierAppController::class, 'profile'])->name('profile');
        Route::put('perfil', [CourierAppController::class, 'updateProfile'])->name('profile.update');

        Route::post('corridas/{delivery}/aceitar', [CourierRunController::class, 'accept'])->name('runs.accept');
        Route::post('corridas/{delivery}/recusar', [CourierRunController::class, 'decline'])->name('runs.decline');
        Route::post('corridas/{delivery}/devolver', [CourierRunController::class, 'giveUp'])->name('runs.give-up');
        Route::post('corridas/{delivery}/retirar', [CourierRunController::class, 'pickUp'])->name('runs.pick-up');
        Route::post('corridas/{delivery}/entregar', [CourierRunController::class, 'complete'])->name('runs.complete');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('stores/create', [StoreController::class, 'create'])->name('stores.create');
    Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
    Route::post('stores/{store}/switch', [StoreController::class, 'switch'])->name('stores.switch');

    Route::middleware('store')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('billing', [BillingController::class, 'show'])->name('billing.show');
        Route::post('billing/redeem', [BillingController::class, 'redeem'])->middleware('throttle:5,1')->name('billing.redeem');

        Route::prefix('deliveries')->name('deliveries.')->middleware(['can:deliver', 'module:couriers'])->group(function () {
            Route::get('/', [MyDeliveriesController::class, 'index'])->name('index');
            Route::post('location', [MyDeliveriesController::class, 'location'])->middleware('throttle:30,1')->name('location');
            Route::post('{order}/depart', [MyDeliveriesController::class, 'depart'])->name('depart');
            Route::post('{order}/complete', [MyDeliveriesController::class, 'complete'])->name('complete');
        });

        Route::prefix('tables')->name('tables.')->middleware(['can:serve-tables', 'module:tables'])->group(function () {
            Route::get('/', [TableController::class, 'index'])->name('index');
            Route::get('{table}', [TableController::class, 'show'])->name('show');
            Route::post('{table}/open', [TableController::class, 'open'])->name('open');
            Route::post('tabs/{tab}/rounds', [TableController::class, 'addRound'])->name('rounds.store');
            Route::post('tabs/{tab}/bill', [TableController::class, 'requestBill'])->name('bill');
            Route::post('tabs/{tab}/transfer', [TableController::class, 'transfer'])->name('transfer');
            Route::post('tabs/{tab}/join', [TableController::class, 'join'])->name('join');
            Route::post('tabs/{tab}/separate', [TableController::class, 'separate'])->name('separate');
            Route::post('tabs/{tab}/release', [TableController::class, 'release'])->name('release');
            Route::post('tabs/{tab}/close', [TableController::class, 'close'])->middleware('can:operate-pos')->name('close');
        });

        Route::middleware('can:view-orders')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/pending', PendingOrdersController::class)->name('orders.pending');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::get('orders/{order}/edit', [OrderEditController::class, 'edit'])->name('orders.edit');
            Route::put('orders/{order}', [OrderEditController::class, 'update'])->name('orders.update');
            Route::post('orders/{order}/changes/{change}/print', [OrderController::class, 'printChange'])->name('orders.changes.print');
            Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status.update');
            Route::patch('orders/{order}/courier', [OrderController::class, 'assignCourier'])->middleware('module:couriers')->name('orders.courier.update');
            Route::post('orders/{order}/print', [OrderController::class, 'print'])->name('orders.print');
        });

        Route::middleware('can:operate-pos')->group(function () {
            Route::put('store-status', StoreStatusController::class)->name('store-status.update');
            Route::post('orders/{order}/network-delivery', [NetworkDeliveryController::class, 'store'])->middleware('throttle:20,1')->name('orders.network.store');
            Route::delete('orders/{order}/network-delivery', [NetworkDeliveryController::class, 'destroy'])->name('orders.network.destroy');
            Route::post('orders/{order}/payment', [OrderController::class, 'receivePayment'])->middleware('module:pos')->name('orders.payment.store');
            Route::get('couriers', [CourierMapController::class, 'index'])->middleware('module:couriers')->name('couriers.map');

            Route::get('pos', [PosController::class, 'show'])->middleware('module:pos')->name('pos.show');
            Route::post('pos/orders', [PosController::class, 'store'])->middleware('module:pos')->name('pos.orders.store');

            Route::get('cash', [CashController::class, 'index'])->middleware('module:pos')->name('cash.index');
            Route::post('cash/open', [CashController::class, 'open'])->middleware('module:pos')->name('cash.open');
            Route::post('cash/movements', [CashController::class, 'move'])->middleware('module:pos')->name('cash.movements.store');
            Route::post('cash/close', [CashController::class, 'close'])->middleware('module:pos')->name('cash.close');
            Route::get('cash/{session}', [CashController::class, 'show'])->middleware('module:pos')->name('cash.show');
            Route::post('cash/{session}/print', [CashController::class, 'print'])->middleware('module:pos')->name('cash.print');
        });

        Route::middleware(['can:manage-store', 'module:reports'])->group(function () {
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
        });

        Route::prefix('finance')->name('finance.')->middleware(['can:manage-finance', 'module:finance'])->group(function () {
            Route::get('/', [FinanceOverviewController::class, 'index'])->name('overview');
            Route::get('payables', [TitleController::class, 'payables'])->name('payables');
            Route::get('receivables', [TitleController::class, 'receivables'])->name('receivables');

            Route::get('titles/create', [TitleController::class, 'create'])->name('titles.create');
            Route::post('titles', [TitleController::class, 'store'])->name('titles.store');
            Route::get('titles/{title}', [TitleController::class, 'show'])->name('titles.show');
            Route::get('titles/{title}/edit', [TitleController::class, 'edit'])->name('titles.edit');
            Route::put('titles/{title}', [TitleController::class, 'update'])->name('titles.update');
            Route::delete('titles/{title}', [TitleController::class, 'destroy'])->name('titles.destroy');
            Route::post('titles/{title}/cancel', [TitleController::class, 'cancel'])->name('titles.cancel');
            Route::post('titles/{title}/reopen', [TitleController::class, 'reopen'])->name('titles.reopen');

            Route::post('installments/{installment}/settle', [InstallmentController::class, 'settle'])->name('installments.settle');
            Route::delete('installments/{installment}/settle', [InstallmentController::class, 'undo'])->name('installments.undo');

            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

            Route::get('categories', [FinanceCategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [FinanceCategoryController::class, 'store'])->name('categories.store');
            Route::put('categories/{category}', [FinanceCategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [FinanceCategoryController::class, 'destroy'])->name('categories.destroy');
        });

        Route::prefix('catalog')->name('catalog.')->middleware('can:manage-catalog')->group(function () {
            Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::resource('option-groups', OptionGroupController::class)->except(['show']);
            Route::resource('products', ProductController::class)->except(['show']);

            Route::prefix('pizzas')->name('pizzas.')->group(function () {
                Route::get('/', [PizzaController::class, 'index'])->name('index');
                Route::put('settings', [PizzaController::class, 'settings'])->name('settings');
                Route::put('prices', [PizzaController::class, 'prices'])->name('prices');
                Route::post('adjust', [PizzaController::class, 'adjust'])->name('adjust');
                Route::post('groups', [PizzaController::class, 'storeGroup'])->name('groups.store');
                Route::put('groups/{group}', [PizzaController::class, 'updateGroup'])->name('groups.update');
                Route::delete('groups/{group}', [PizzaController::class, 'destroyGroup'])->name('groups.destroy');
                Route::post('flavors', [PizzaController::class, 'storeFlavor'])->name('flavors.store');
                Route::put('flavors/{flavor}', [PizzaController::class, 'updateFlavor'])->name('flavors.update');
                Route::patch('flavors/{flavor}/toggle', [PizzaController::class, 'toggleFlavor'])->name('flavors.toggle');
                Route::delete('flavors/{flavor}', [PizzaController::class, 'destroyFlavor'])->name('flavors.destroy');
                Route::post('crusts', [PizzaController::class, 'storeCrust'])->name('crusts.store');
                Route::put('crusts/{crust}', [PizzaController::class, 'updateCrust'])->name('crusts.update');
                Route::delete('crusts/{crust}', [PizzaController::class, 'destroyCrust'])->name('crusts.destroy');
            });
        });

        Route::prefix('marketing')->name('marketing.')->middleware(['can:manage-store', 'module:marketing'])->group(function () {
            Route::resource('coupons', CouponController::class)->except(['show']);

            Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
            Route::post('banners', [BannerController::class, 'store'])->name('banners.store');
            Route::put('banners/{banner}', [BannerController::class, 'update'])->name('banners.update');
            Route::patch('banners/{banner}/move', [BannerController::class, 'move'])->name('banners.move');
            Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
        });

        Route::prefix('store-settings')->name('store-settings.')->middleware('can:manage-store')->group(function () {
            Route::get('delivery', [DeliveryController::class, 'edit'])->name('delivery.edit');
            Route::put('delivery', [DeliveryController::class, 'update'])->name('delivery.update');
            Route::post('delivery/zones', [DeliveryController::class, 'storeZone'])->name('delivery.zones.store');
            Route::put('delivery/zones/{zone}', [DeliveryController::class, 'updateZone'])->name('delivery.zones.update');
            Route::delete('delivery/zones/{zone}', [DeliveryController::class, 'destroyZone'])->name('delivery.zones.destroy');

            Route::get('printing', [PrintingController::class, 'edit'])->name('printing.edit');
            Route::put('printing', [PrintingController::class, 'update'])->name('printing.update');
            Route::post('printing/pairing-code', [PrintingController::class, 'pairingCode'])->name('printing.pairing-code');
            Route::post('printing/printers', [PrintingController::class, 'savePrinter'])->name('printing.printers.save');
            Route::delete('printing/printers/{printer}', [PrintingController::class, 'destroyPrinter'])->name('printing.printers.destroy');
            Route::post('printing/printers/{printer}/test', [PrintingController::class, 'testPrinter'])->name('printing.printers.test');
            Route::delete('printing/agents/{agent}', [PrintingController::class, 'destroyAgent'])->name('printing.agents.destroy');

            Route::get('courier-network', [CourierNetworkController::class, 'edit'])->name('courier-network.edit');
            Route::put('courier-network', [CourierNetworkController::class, 'update'])->name('courier-network.update');

            Route::get('contact', [ContactController::class, 'edit'])->name('contact.edit');
            Route::put('contact', [ContactController::class, 'update'])->name('contact.update');

            Route::get('appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
            Route::put('appearance', [AppearanceController::class, 'update'])->name('appearance.update');

            Route::get('payments', [PaymentsController::class, 'edit'])->name('payments.edit');
            Route::put('payments', [PaymentsController::class, 'update'])->name('payments.update');

            Route::prefix('online-payments')->name('online-payments.')->middleware('module:online_payments')->group(function () {
                Route::get('/', [OnlinePaymentsController::class, 'edit'])->name('edit');
                Route::get('mercadopago/connect', [OnlinePaymentsController::class, 'connectMercadoPago'])->name('mercadopago.connect');
                Route::get('mercadopago/callback', [OnlinePaymentsController::class, 'mercadoPagoCallback'])->name('mercadopago.callback');
                Route::post('mercadopago/token', [OnlinePaymentsController::class, 'mercadoPagoToken'])->middleware('throttle:10,1')->name('mercadopago.token');
                Route::post('sicoob', [OnlinePaymentsController::class, 'sicoob'])->middleware('throttle:10,1')->name('sicoob');
                Route::post('btcpay', [OnlinePaymentsController::class, 'btcpay'])->middleware('throttle:10,1')->name('btcpay');
                Route::put('{account}', [OnlinePaymentsController::class, 'update'])->name('update');
                Route::delete('{account}', [OnlinePaymentsController::class, 'destroy'])->name('destroy');
            });

            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::post('team', [TeamController::class, 'store'])->name('team.store');
            Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
            Route::put('team/{member}/password', [TeamController::class, 'resetPassword'])->name('team.password');
            Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

            Route::get('tables', [TablesController::class, 'edit'])->middleware('module:tables')->name('tables.edit');
            Route::put('tables', [TablesController::class, 'update'])->middleware('module:tables')->name('tables.update');
            Route::post('tables/items', [TablesController::class, 'store'])->middleware('module:tables')->name('tables.items.store');
            Route::put('tables/items/{table}', [TablesController::class, 'updateTable'])->middleware('module:tables')->name('tables.items.update');
            Route::delete('tables/items/{table}', [TablesController::class, 'destroy'])->middleware('module:tables')->name('tables.items.destroy');

            Route::get('hours', [HoursController::class, 'edit'])->name('hours.edit');
            Route::put('hours', [HoursController::class, 'update'])->name('hours.update');

            Route::get('messages', [MessagesController::class, 'edit'])->middleware('module:whatsapp')->name('messages.edit');
            Route::put('messages', [MessagesController::class, 'update'])->middleware('module:whatsapp')->name('messages.update');
            Route::put('messages/template', [MessagesController::class, 'updateTemplate'])->middleware('module:whatsapp')->name('messages.template.update');
            Route::delete('messages/template', [MessagesController::class, 'resetTemplate'])->middleware('module:whatsapp')->name('messages.template.reset');
            Route::post('messages/whatsapp/connect', [WhatsAppConnectionController::class, 'connect'])
                ->middleware('throttle:10,1')
                ->middleware('module:whatsapp')->name('messages.whatsapp.connect');
            Route::delete('messages/whatsapp', [WhatsAppConnectionController::class, 'disconnect'])->middleware('module:whatsapp')->name('messages.whatsapp.disconnect');
        });
    });
});

// Private panel of the platform owner (no store needed).
Route::middleware(['auth', 'verified', 'can:super-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('stores', [AdminController::class, 'stores'])->name('stores.index');
    Route::get('stores/{store}', [AdminController::class, 'store'])->name('stores.show');
    Route::post('stores/{store}/suspend', [AdminController::class, 'suspend'])->name('stores.suspend');
    Route::post('stores/{store}/reactivate', [AdminController::class, 'reactivate'])->name('stores.reactivate');
    Route::post('stores/{store}/support', [AdminController::class, 'startSupport'])->name('stores.support');
    Route::post('support/stop', [AdminController::class, 'stopSupport'])->name('support.stop');

    Route::get('plans', [BillingAdminController::class, 'plans'])->name('plans.index');
    Route::post('plans', [BillingAdminController::class, 'storePlan'])->name('plans.store');
    Route::put('plans/{plan}', [BillingAdminController::class, 'updatePlan'])->name('plans.update');
    Route::get('vouchers', [BillingAdminController::class, 'vouchers'])->name('vouchers.index');
    Route::post('vouchers', [BillingAdminController::class, 'generateVouchers'])->name('vouchers.store');
    Route::patch('vouchers/{voucher}/toggle', [BillingAdminController::class, 'toggleVoucher'])->name('vouchers.toggle');
    Route::post('stores/{store}/billing/extend', [BillingAdminController::class, 'extend'])->name('stores.billing.extend');
    Route::put('stores/{store}/billing/plan', [BillingAdminController::class, 'setPlan'])->name('stores.billing.plan');
    Route::put('stores/{store}/billing/complimentary', [BillingAdminController::class, 'complimentary'])->name('stores.billing.complimentary');
    Route::get('invoices', [InvoiceAdminController::class, 'index'])->name('invoices.index');
    Route::post('invoices/{invoice}/settle', [InvoiceAdminController::class, 'settle'])->name('invoices.settle');
    Route::post('invoices/{invoice}/reopen', [InvoiceAdminController::class, 'reopen'])->name('invoices.reopen');

    Route::prefix('network')->name('network.')->group(function () {
        Route::get('/', [NetworkAdminController::class, 'index'])->name('index');
        Route::put('settings', [NetworkAdminController::class, 'updateSettings'])->name('settings');
        Route::get('couriers', [CourierAdminController::class, 'index'])->name('couriers.index');
        Route::get('couriers/{courier}', [CourierAdminController::class, 'show'])->name('couriers.show');
        Route::post('couriers/{courier}/review', [CourierAdminController::class, 'review'])->name('couriers.review');
        Route::get('couriers/{courier}/document', [CourierAdminController::class, 'document'])->name('couriers.document');
        Route::get('bills', [NetworkFinanceAdminController::class, 'bills'])->name('bills.index');
        Route::post('bills/{bill}/settle', [NetworkFinanceAdminController::class, 'settleBill'])->name('bills.settle');
        Route::post('bills/{bill}/reopen', [NetworkFinanceAdminController::class, 'reopenBill'])->name('bills.reopen');
        Route::get('payouts', [NetworkFinanceAdminController::class, 'payouts'])->name('payouts.index');
        Route::get('payouts/export', [NetworkFinanceAdminController::class, 'exportPayouts'])->name('payouts.export');
        Route::post('payouts/{payout}/pay', [NetworkFinanceAdminController::class, 'payPayout'])->name('payouts.pay');
        Route::post('payouts/{payout}/reopen', [NetworkFinanceAdminController::class, 'reopenPayout'])->name('payouts.reopen');
    });
});

require __DIR__.'/settings.php';

// Customer-facing menu at /{store-slug}. Registered last so it never shadows the app routes;
// every first path segment used above is listed in StoreCreateRequest::RESERVED_SLUGS.
Route::prefix('{storeSlug}')
    ->where(['storeSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->name('menu.')
    ->middleware(EnsureMenuAvailable::class)
    ->group(function () {
        Route::get('/', [PublicMenuController::class, 'show'])->name('show');
        Route::post('orders', [PublicMenuController::class, 'placeOrder'])
            ->middleware('throttle:10,1')
            ->name('orders.store');
        Route::post('coupon', [PublicMenuController::class, 'checkCoupon'])
            ->middleware('throttle:20,1')
            ->name('coupon.check');
        Route::get('orders/{token}', [PublicMenuController::class, 'showOrder'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->name('orders.show');
        Route::get('orders/{token}/payment', [PublicMenuController::class, 'paymentStatus'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->middleware('throttle:40,1')
            ->name('orders.payment');
    });
