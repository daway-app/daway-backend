<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1.1 — relationship integrity for `order_items` / `order_status_histories`
 * → `sub_orders`.
 *
 * Proves that the composite foreign key is REALLY present (not just an index) and
 * that it enforces the cross-parent rule, while legacy NULL rows stay valid.
 */
class SubOrderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Pharmacy $pharmacyA;
    private Pharmacy $pharmacyB;
    private PharmacyMedicine $pmA;
    private PharmacyMedicine $pmB;
    private User $patient;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 2 — إحداثيات قريبة (≈167 م) لأن checkout يفرض حد 500 م بين
        // أبعد صيدليتين؛ إحداثيات الـfactory العشوائية كانت ستُرفض.
        $this->pharmacyA = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5000,
            'longitude' => 34.4700,
        ]);
        $this->pharmacyB = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5015,
            'longitude' => 34.4700,
        ]);

        $this->pmA = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacyA->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 50.00,
        ]);
        $this->pmB = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacyB->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 30.00,
        ]);

        $this->patient = User::factory()->patient()->create();
        $this->address = Address::factory()->for($this->patient)->create();

        $this->actingAs($this->patient, 'sanctum');
    }

    private function seedTwoPharmacyCart(): void
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $this->patient->id],
            ['items_count' => 0, 'subtotal' => 0]
        );
        $cart->items()->delete();

        foreach ([['pm' => $this->pmA, 'q' => 1], ['pm' => $this->pmB, 'q' => 1]] as $line) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'pharmacy_id' => $line['pm']->pharmacy_id,
                'pharmacy_medicine_id' => $line['pm']->id,
                'quantity' => $line['q'],
                'price' => (float) $line['pm']->price,
            ]);
        }
    }

    private function multiPharmacyOrder(): Order
    {
        $this->seedTwoPharmacyCart();
        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'idempotency_key' => 'integrity-'.uniqid(),
        ])->assertStatus(201);

        return Order::latest('id')->firstOrFail();
    }

    /** Raw columns required to insert an order_items row without the model. */
    private function itemRow(Order $order, ?int $subOrderId, PharmacyMedicine $pm): array
    {
        return [
            'order_id' => $order->id,
            'sub_order_id' => $subOrderId,
            'pharmacy_id' => $pm->pharmacy_id,
            'pharmacy_medicine_id' => $pm->id,
            'medicine_trade_name' => 'Integrity Probe',
            'quantity' => 1,
            'unit_price' => 10,
            'total_price' => 10,
            'availability_status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // ── Constraint presence ──────────────────────────────────────────────────

    public function test_composite_fk_exists_on_order_items(): void
    {
        $froms = $this->subOrderFkColumns('order_items');

        $this->assertContains('sub_order_id', $froms);
        $this->assertContains('order_id', $froms);
    }

    public function test_composite_fk_exists_on_order_status_histories(): void
    {
        $froms = $this->subOrderFkColumns('order_status_histories');

        $this->assertContains('sub_order_id', $froms);
        $this->assertContains('order_id', $froms);
    }

    // ── Phase 1.2 — pharmacy integrity ───────────────────────────────────────

    public function test_order_items_fk_also_covers_pharmacy_id(): void
    {
        $this->assertContains(
            'pharmacy_id',
            $this->subOrderFkColumns('order_items'),
            'Phase 1.2: order_items FK must be (sub_order_id, order_id, pharmacy_id)'
        );
    }

    public function test_phase_1_1_fk_on_histories_is_not_regressed(): void
    {
        $froms = $this->subOrderFkColumns('order_status_histories');

        // order_status_histories has no pharmacy_id column, so its Phase 1.1
        // two-column FK must remain exactly as it was.
        $this->assertNotContains('pharmacy_id', $froms);
        $this->assertContains('sub_order_id', $froms);
        $this->assertContains('order_id', $froms);
    }

    public function test_phase_1_2_parent_key_exists_on_sub_orders(): void
    {
        $this->assertTrue(
            DB::table('sqlite_master')->where('type', 'index')
                ->where('name', 'sub_orders_id_order_id_pharmacy_unique')->exists(),
            'sub_orders needs UNIQUE (id, order_id, pharmacy_id) as the Phase 1.2 FK target'
        );
    }

    public function test_item_with_matching_pharmacy_is_accepted(): void
    {
        $order = $this->multiPharmacyOrder();
        $subA = $order->subOrders()->where('pharmacy_id', $this->pharmacyA->id)->firstOrFail();
        $before = OrderItem::where('sub_order_id', $subA->id)->count();

        DB::table('order_items')->insert(
            $this->itemRow($order, $subA->id, $this->pmA) // pharmacy A == sub-order's pharmacy
        );

        $this->assertSame($before + 1, OrderItem::where('sub_order_id', $subA->id)->count());
    }

    public function test_item_with_wrong_pharmacy_is_rejected(): void
    {
        $order = $this->multiPharmacyOrder();
        $subA = $order->subOrders()->where('pharmacy_id', $this->pharmacyA->id)->firstOrFail();

        $this->expectException(QueryException::class);

        // Same order, same sub-order — but the item claims a DIFFERENT pharmacy.
        DB::table('order_items')->insert(
            $this->itemRow($order, $subA->id, $this->pmB)
        );
    }

    public function test_deleting_a_pharmacy_keeps_parent_order_and_sub_order(): void
    {
        $order = $this->multiPharmacyOrder();
        $subA = $order->subOrders()->where('pharmacy_id', $this->pharmacyA->id)->firstOrFail();
        $subB = $order->subOrders()->where('pharmacy_id', $this->pharmacyB->id)->firstOrFail();
        $itemsB = $subB->items()->pluck('id')->all();

        // Mirror Admin\PharmacyController::destroy (hard delete of user + pharmacy).
        $this->pharmacyA->user->delete();
        $this->pharmacyA->delete();

        // A's items cascade away with the pharmacy; the sub-order survives as a
        // historical shell with pharmacy_id = NULL.
        $this->assertSame(0, OrderItem::where('sub_order_id', $subA->id)->count());
        $this->assertDatabaseHas('sub_orders', ['id' => $subA->id, 'pharmacy_id' => null]);

        // The parent order, the sibling sub-order and its items are untouched.
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('sub_orders', ['id' => $subB->id]);
        $this->assertSame(count($itemsB), OrderItem::whereIn('id', $itemsB)->count());
    }

    public function test_parent_key_unique_index_exists_on_sub_orders(): void
    {
        $this->assertTrue(
            DB::table('sqlite_master')->where('type', 'index')
                ->where('name', 'sub_orders_id_order_id_unique')->exists(),
            'sub_orders needs UNIQUE (id, order_id) as the composite FK target'
        );
    }

    // ── Cross-parent enforcement ─────────────────────────────────────────────

    public function test_item_cannot_reference_a_sub_order_of_another_parent_order(): void
    {
        $firstOrder = $this->multiPharmacyOrder();
        $secondOrder = $this->multiPharmacyOrder();

        $foreignSubOrder = $secondOrder->subOrders()->firstOrFail();
        $this->assertNotSame($firstOrder->id, $foreignSubOrder->order_id);

        $this->expectException(QueryException::class);

        // order_id = firstOrder, but the sub-order belongs to secondOrder.
        DB::table('order_items')->insert(
            $this->itemRow($firstOrder, $foreignSubOrder->id, $this->pmA)
        );
    }

    public function test_history_cannot_reference_a_sub_order_of_another_parent_order(): void
    {
        $firstOrder = $this->multiPharmacyOrder();
        $secondOrder = $this->multiPharmacyOrder();

        $foreignSubOrder = $secondOrder->subOrders()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('order_status_histories')->insert([
            'order_id' => $firstOrder->id,
            'sub_order_id' => $foreignSubOrder->id,
            'status' => 'preparing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_item_cannot_reference_a_non_existent_sub_order(): void
    {
        $order = $this->multiPharmacyOrder();

        $this->expectException(QueryException::class);

        DB::table('order_items')->insert(
            $this->itemRow($order, 999999, $this->pmA)
        );
    }

    public function test_matching_parent_order_is_accepted(): void
    {
        $order = $this->multiPharmacyOrder();
        $ownSubOrder = $order->subOrders()->where('pharmacy_id', $this->pharmacyA->id)->firstOrFail();

        $before = OrderItem::where('sub_order_id', $ownSubOrder->id)->count();

        // Same order_id as the sub-order's parent → valid.
        DB::table('order_items')->insert(
            $this->itemRow($order, $ownSubOrder->id, $this->pmA)
        );

        $this->assertSame(
            $before + 1,
            OrderItem::where('sub_order_id', $ownSubOrder->id)->count()
        );
    }

    // ── Legacy rows ──────────────────────────────────────────────────────────

    public function test_legacy_row_with_null_sub_order_id_is_still_valid(): void
    {
        $order = $this->multiPharmacyOrder();

        // A legacy row: order_id set, sub_order_id NULL. The composite FK must
        // NOT be enforced for it (MATCH SIMPLE: any NULL column ⇒ satisfied).
        DB::table('order_items')->insert(
            $this->itemRow($order, null, $this->pmA)
        );

        $legacy = OrderItem::whereNull('sub_order_id')->firstOrFail();
        $this->assertNull($legacy->sub_order_id);
        $this->assertSame($order->id, $legacy->order_id);
    }

    // ── Deletion policy ──────────────────────────────────────────────────────

    public function test_deleting_a_sub_order_cascades_only_its_own_items(): void
    {
        $order = $this->multiPharmacyOrder();

        $subA = $order->subOrders()->where('pharmacy_id', $this->pharmacyA->id)->firstOrFail();
        $subB = $order->subOrders()->where('pharmacy_id', $this->pharmacyB->id)->firstOrFail();

        // A legacy row on the same order (NULL sub_order_id) — must survive.
        DB::table('order_items')->insert($this->itemRow($order, null, $this->pmA));
        $legacyId = OrderItem::whereNull('sub_order_id')->value('id');

        $itemAIds = $subA->items()->pluck('id')->all();
        $itemBIds = $subB->items()->pluck('id')->all();
        $this->assertNotEmpty($itemAIds);
        $this->assertNotEmpty($itemBIds);

        $subA->delete();

        // A's items gone.
        $this->assertSame(0, OrderItem::whereIn('id', $itemAIds)->count());
        // B's items untouched.
        $this->assertSame(count($itemBIds), OrderItem::whereIn('id', $itemBIds)->count());
        // Legacy row untouched.
        $this->assertDatabaseHas('order_items', ['id' => $legacyId, 'sub_order_id' => null]);
        // Parent order and sibling sub-order survive.
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('sub_orders', ['id' => $subB->id]);
    }

    public function test_deleting_a_sub_order_never_deletes_the_parent_order(): void
    {
        $order = $this->multiPharmacyOrder();
        $subA = $order->subOrders()->where('pharmacy_id', $this->pharmacyA->id)->firstOrFail();

        $subA->delete();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(1, Order::whereKey($order->id)->count());
    }

    public function test_deleting_a_parent_order_removes_sub_orders_and_items_consistently(): void
    {
        $order = $this->multiPharmacyOrder();
        $orderId = $order->id;
        $subIds = $order->subOrders()->pluck('id')->all();

        $order->delete();

        $this->assertSame(0, SubOrder::whereIn('id', $subIds)->count());
        $this->assertSame(0, OrderItem::where('order_id', $orderId)->count());
        $this->assertSame(0, Order::whereKey($orderId)->count());
    }

    // ── helper ───────────────────────────────────────────────────────────────

    private function hasCompositeFk(string $table): bool
    {
        return in_array('sub_order_id', $this->subOrderFkColumns($table), true);
    }

    /**
     * The child columns of the FK that points at `sub_orders`.
     * A composite FK produces one PRAGMA row per column.
     *
     * @return array<int, string>
     */
    private function subOrderFkColumns(string $table): array
    {
        $rows = DB::select('PRAGMA foreign_key_list("'.str_replace('"', '""', $table).'")');

        return collect($rows)
            ->filter(fn ($r) => $r->table === 'sub_orders')
            ->pluck('from')
            ->all();
    }
}
