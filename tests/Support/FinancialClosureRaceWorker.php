<?php

// Run only against the dedicated, local MySQL instance configured by the race test.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AccountClosureRequest;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Order;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Accounting\RefundService;
use App\Services\Accounting\AccountingLedger;
use App\Models\User;
use App\Services\AccountClosure\AccountClosureBlocked;
use App\Services\AccountClosure\AccountClosureService;
use App\Services\PlatformSettlementService;
use Illuminate\Support\Facades\DB;

$c = config('database.connections.mysql');
if (getenv('DAWAK_FINANCIAL_RACE') !== '1' || config('database.default') !== 'mysql'
    || $c['host'] !== '127.0.0.1' || (string) $c['port'] !== '33317'
    || $c['database'] !== 'dawak_financial_race_test' || config('app.env') !== 'testing') {
    throw new RuntimeException('Race tests require the dedicated local test database.');
}
$dir = $argv[2];
$wait = static function (string $file): void {
    $deadline = microtime(true) + 90;
    while (! is_file($file)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Barrier timed out: '.basename($file));
        usleep(50000);
    }
};
if ($argv[1] === 'setup') {
    $pharmacy = Pharmacy::factory()->create(['pharmacy_custom_id' => 'RACE-'.bin2hex(random_bytes(8))]);
    $admin = User::factory()->admin()->create();
    $request = AccountClosureRequest::create(['subject_type' => Pharmacy::class, 'subject_id' => $pharmacy->id, 'status' => 'pending']);
    $sale = Sale::create([
        'pharmacy_id' => $pharmacy->id, 'number' => 'RACE-'.bin2hex(random_bytes(8)),
        'subtotal' => 100, 'discount' => 0, 'total' => 100, 'paid' => 100, 'remaining' => 0,
        'payment_method' => Sale::METHOD_CARD, 'status' => Sale::STATUS_PAID,
        'items_count' => 1, 'created_by' => $pharmacy->user_id, 'sold_at' => now(),
    ]);
    $item = SaleItem::create([
        'sale_id' => $sale->id, 'medicine_name' => 'Race fixture', 'unit_price' => 100,
        'quantity' => 1, 'line_discount' => 0, 'line_total' => 100,
    ]);
    $patient = User::factory()->patient()->create();
    $address = Address::factory()->for($patient)->create();
    $medicine = PharmacyMedicine::factory()->create([
        'pharmacy_id' => $pharmacy->id, 'quantity' => 10, 'is_available' => true, 'price' => 50,
    ]);
    $cart = Cart::create(['user_id' => $patient->id, 'items_count' => 1, 'subtotal' => 50]);
    CartItem::factory()->create([
        'cart_id' => $cart->id, 'pharmacy_id' => $pharmacy->id,
        'pharmacy_medicine_id' => $medicine->id, 'quantity' => 1, 'price' => 50,
    ]);
    $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Race customer']);
    $supplier = Supplier::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Race supplier']);
    file_put_contents($dir.'/ids.json', json_encode([$pharmacy->id, $admin->id, $request->id, $sale->id, $item->id, $patient->id, $address->id, $customer->id, $supplier->id], JSON_THROW_ON_ERROR));
    exit;
}
[$pid, $aid, $rid, $saleId, $itemId, $patientId, $addressId, $customerId, $supplierId] = json_decode(file_get_contents($dir.'/ids.json'), true, 512, JSON_THROW_ON_ERROR);
$service = app(PlatformSettlementService::class);
$closure = app(AccountClosureService::class);
if ($argv[1] === 'close_holds') {
    DB::transaction(function () use ($closure, $rid, $aid, $dir, $wait): void {
        $closure->approvePharmacyClosure(AccountClosureRequest::findOrFail($rid), User::findOrFail($aid));
        file_put_contents($dir.'/held', '1');
        $wait($dir.'/release');
    });
    echo json_encode(['result' => 'closed']);
} elseif ($argv[1] === 'adjust_holds') {
    DB::transaction(function () use ($service, $pid, $aid, $dir, $wait): void {
        $service->addAdjustment(Pharmacy::findOrFail($pid), date('Y-m-d'), 10, 'race test', $aid);
        file_put_contents($dir.'/held', '1');
        $wait($dir.'/release');
    });
    echo json_encode(['result' => 'adjusted']);
} elseif ($argv[1] === 'adjust_contends') {
    $pharmacy = Pharmacy::findOrFail($pid);
    file_put_contents($dir.'/started', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    try {
        $service->addAdjustment($pharmacy, date('Y-m-d'), 10, 'race test', $aid);
        echo json_encode(['result' => 'created']);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['result' => 'rejected', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null,
            'count' => DB::table('platform_settlement_adjustments')->where('pharmacy_id', $pid)->count()]);
    }
} elseif (in_array($argv[1], ['sale_contends', 'purchase_contends'], true)) {
    $ownerId = Pharmacy::findOrFail($pid)->user_id;
    $number = 'RACE-NEW-'.bin2hex(random_bytes(8));
    file_put_contents($dir.'/started', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    try {
        if ($argv[1] === 'sale_contends') {
            AccountingLedger::recordSale($pid, $ownerId, ['number' => $number, 'payment_method' => 'card',
                'items' => [['medicine_name' => 'race', 'unit_price' => 10, 'quantity' => 1]]]);
        } else {
            AccountingLedger::recordPurchase($pid, $ownerId, ['number' => $number, 'payment_method' => 'credit',
                'items' => [['medicine_name' => 'race', 'unit_cost' => 10, 'quantity' => 1]]]);
        }
        echo json_encode(['result' => 'created', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null]);
    } catch (InvalidArgumentException $e) {
        $table = $argv[1] === 'sale_contends' ? 'sales' : 'purchases';
        echo json_encode(['result' => 'rejected', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null,
            'count' => DB::table($table)->where('number', $number)->count(), 'message' => $e->getMessage()]);
    }
} elseif (in_array($argv[1], ['expense_contends', 'customer_payment_contends', 'supplier_payment_contends', 'cash_adjustment_contends'], true)) {
    $ownerId = Pharmacy::findOrFail($pid)->user_id;
    $customer = Customer::findOrFail($customerId);
    $supplier = Supplier::findOrFail($supplierId);
    file_put_contents($dir.'/started', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    try {
        if ($argv[1] === 'expense_contends') {
            AccountingLedger::recordExpense($pid, $ownerId, ['amount' => 10, 'payment_method' => 'card']);
            $table = 'expenses';
        } elseif ($argv[1] === 'customer_payment_contends') {
            AccountingLedger::recordCustomerPayment($pid, $ownerId, $customer, ['amount' => 10, 'payment_method' => 'card']);
            $table = 'customer_payments';
        } elseif ($argv[1] === 'supplier_payment_contends') {
            AccountingLedger::recordSupplierPayment($pid, $ownerId, $supplier, ['amount' => 10, 'payment_method' => 'card']);
            $table = 'supplier_payments';
        } else {
            AccountingLedger::recordCashAdjustment($pid, $ownerId, 'in', 10, 'race test');
            $table = 'cash_movements';
        }
        echo json_encode(['result' => 'created', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null]);
    } catch (InvalidArgumentException $e) {
        $table = match ($argv[1]) {
            'expense_contends' => 'expenses', 'customer_payment_contends' => 'customer_payments',
            'supplier_payment_contends' => 'supplier_payments', 'cash_adjustment_contends' => 'cash_movements',
        };
        echo json_encode(['result' => 'rejected', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null,
            'count' => DB::table($table)->where('pharmacy_id', $pid)->count(), 'message' => $e->getMessage()]);
    }
} elseif ($argv[1] === 'checkout_contends') {
    $patient = User::findOrFail($patientId);
    $http = Request::create('/api/patient/checkout', 'POST', ['address_id' => $addressId, 'idempotency_key' => 'race-'.bin2hex(random_bytes(8))]);
    $http->setUserResolver(static fn () => $patient);
    file_put_contents($dir.'/started', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    try {
        $response = app(OrderController::class)->checkout($http);
        echo json_encode(['result' => 'created', 'status' => $response->getStatusCode()]);
    } catch (ValidationException $e) {
        echo json_encode(['result' => 'rejected', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null,
            'count' => Order::where('user_id', $patientId)->count()]);
    }
} elseif ($argv[1] === 'refund_contends') {
    $sale = Sale::findOrFail($saleId);
    $ownerId = Pharmacy::findOrFail($pid)->user_id;
    file_put_contents($dir.'/started', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    try {
        $refund = RefundService::processRefund($pid, $ownerId, $sale, [['sale_item_id' => $itemId, 'quantity' => 1]]);
        echo json_encode(['result' => 'created', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null, 'refund_id' => $refund->id]);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['result' => 'rejected', 'closed' => Pharmacy::findOrFail($pid)->closed_at !== null,
            'count' => DB::table('refunds')->where('pharmacy_id', $pid)->count(), 'message' => $e->getMessage()]);
    }
} elseif ($argv[1] === 'close_contends') {
    $request = AccountClosureRequest::findOrFail($rid);
    $admin = User::findOrFail($aid);
    file_put_contents($dir.'/started', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    try {
        $closure->approvePharmacyClosure($request, $admin);
        echo json_encode(['result' => 'closed']);
    } catch (AccountClosureBlocked $e) {
        echo json_encode(['result' => 'blocked', 'blockers' => array_column($e->blockers(), 'key'),
            'closed' => Pharmacy::findOrFail($pid)->closed_at !== null]);
    }
}
