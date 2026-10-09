<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The school store: stock, hand-overs, parent confirmation, and fees. */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $bursar;
    private User $parent;
    private User $otherParent;
    private User $teacher;
    private Student $ada;
    private Term $term;
    private SchoolClass $jss1;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->admin = $this->user('Mrs Adeyemi', 'school_admin');
        $this->bursar = $this->user('Mr Eze', 'bursar');
        $this->teacher = $this->user('Mr Musa', 'teacher');
        $this->parent = $this->user('Mr Obi', 'parent');
        $this->otherParent = $this->user('Mrs Bello', 'parent');
        $adaUser = $this->user('Ada Obi', 'student');
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $adaUser->id, 'class_id' => $this->jss1->id, 'admission_number' => 'HT/001', 'status' => 'active']);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id])->students()->attach($this->ada->id);

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear(), 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'is_current' => true]);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function switchOn(): void
    {
        $this->as($this->admin)->putJson("{$this->host}/inventory/settings", ['enabled' => true])->assertOk();
    }

    private function item(array $attrs, int $stock = 10): int
    {
        return $this->as($this->admin)->postJson("{$this->host}/inventory/items", $attrs + ['opening_stock' => $stock])->assertCreated()->json('item.id');
    }

    private function invoice(float $total = 150000, float $paid = 0): Invoice
    {
        return Invoice::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'invoice_number' => 'INV-1', 'total_amount' => $total, 'amount_paid' => $paid, 'status' => $paid > 0 ? 'partial' : 'unpaid']);
    }

    public function test_the_store_is_off_until_the_school_switches_it_on(): void
    {
        $this->as($this->admin)->getJson("{$this->host}/inventory/settings")->assertOk()->assertJson(['enabled' => false]);
        $this->as($this->admin)->getJson("{$this->host}/inventory/items")->assertForbidden()->assertJson(['code' => 'inventory_off']);
        $this->as($this->parent)->getJson("{$this->host}/parent/inventory")->assertOk()->assertJson(['enabled' => false, 'data' => []]);

        $this->switchOn();

        $this->as($this->admin)->getJson("{$this->host}/inventory/items")->assertOk();
    }

    public function test_only_the_admin_can_switch_it_and_teachers_cannot_use_the_desk(): void
    {
        $this->as($this->bursar)->putJson("{$this->host}/inventory/settings", ['enabled' => true])->assertForbidden();
        $this->switchOn();
        $this->as($this->bursar)->getJson("{$this->host}/inventory/items")->assertOk();
        $this->as($this->teacher)->getJson("{$this->host}/inventory/items")->assertForbidden();
    }

    public function test_the_operator_module_switch_closes_it_even_when_the_school_has_it_on(): void
    {
        $this->switchOn();
        $this->school->forceFill(['module_switches' => json_encode(['inventory' => false])])->save();

        $this->as($this->admin)->getJson("{$this->host}/inventory/items")->assertForbidden()->assertJson(['code' => 'module_disabled']);
    }

    public function test_receiving_and_correcting_stock_leaves_a_trail(): void
    {
        $this->switchOn();
        $id = $this->item(['name' => 'School shirt', 'variant' => 'Age 10-11', 'category' => 'uniform', 'unit_price' => 4500], 5);

        $this->as($this->admin)->postJson("{$this->host}/inventory/items/{$id}/stock", ['change' => 20, 'reason' => 'received', 'note' => 'Tailor delivery'])
            ->assertOk()->assertJsonPath('item.quantity_on_hand', 25);
        $this->as($this->admin)->postJson("{$this->host}/inventory/items/{$id}/stock", ['change' => -30, 'reason' => 'adjusted'])->assertStatus(409);
        $this->as($this->admin)->postJson("{$this->host}/inventory/items/{$id}/stock", ['change' => -5, 'reason' => 'received'])->assertStatus(409);

        $moves = $this->as($this->admin)->getJson("{$this->host}/inventory/items/{$id}/movements")->assertOk()->json('data');
        $this->assertSame([20, 5], array_column($moves, 'change'));
        $this->assertSame(25, $moves[0]['balance_after']);
    }

    public function test_the_same_item_in_a_different_size_is_a_separate_stock_line(): void
    {
        $this->switchOn();
        $this->item(['name' => 'School shirt', 'variant' => 'Age 8-9', 'category' => 'uniform', 'unit_price' => 4000]);
        $this->item(['name' => 'School shirt', 'variant' => 'Age 10-11', 'category' => 'uniform', 'unit_price' => 4500]);

        $this->as($this->admin)->postJson("{$this->host}/inventory/items", ['name' => 'School shirt', 'variant' => 'Age 8-9', 'category' => 'uniform', 'unit_price' => 4000])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_handing_over_a_pack_takes_stock_and_charges_only_the_charged_items_onto_the_term_invoice(): void
    {
        $this->switchOn();
        $invoice = $this->invoice();
        $shirt = $this->item(['name' => 'School shirt', 'variant' => 'Age 10-11', 'category' => 'uniform', 'unit_price' => 4500], 10);
        $book = $this->item(['name' => 'Basic Science for JSS 1', 'category' => 'book', 'unit_price' => 3200], 10);
        $card = $this->item(['name' => 'Exercise books (pack of 12)', 'category' => 'consumable', 'unit_price' => 2400, 'charge_to_fees' => false], 10);

        $pack = $this->as($this->admin)->postJson("{$this->host}/inventory/packs", [
            'name' => 'JSS 1 new student pack', 'class_id' => $this->jss1->id,
            'items' => [['item_id' => $shirt, 'quantity' => 2], ['item_id' => $book, 'quantity' => 1], ['item_id' => $card, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('pack.charged_total', 12200)->json('pack.id');

        $issue = $this->as($this->bursar)->postJson("{$this->host}/inventory/issues", ['student_id' => $this->ada->id, 'pack_id' => $pack])
            ->assertCreated()->json('issue');

        $this->assertSame('awaiting', $issue['status']);
        $this->assertSame($this->term->id, $issue['term_id']); // the current term, in full
        $this->assertEquals(12200, $issue['charged_total']);
        $this->assertSame(8, DB::table('inventory_items')->where('id', $shirt)->value('quantity_on_hand'));
        $this->assertSame(9, DB::table('inventory_items')->where('id', $card)->value('quantity_on_hand'));

        $invoice->refresh();
        $this->assertEquals(162200, (float) $invoice->total_amount);
        $this->assertSame(2, InvoiceItem::where('invoice_id', $invoice->id)->count());
        $this->assertTrue(InvoiceItem::where('title', 'Store: School shirt (Age 10-11) × 2')->where('amount', 9000)->exists());

        // The parent is asked to confirm.
        $this->assertTrue(DB::table('notification_logs')->where('user_id', $this->parent->id)->where('category', 'inventory')->exists());
    }

    public function test_a_hand_over_is_refused_whole_when_any_item_is_short(): void
    {
        $this->switchOn();
        $shirt = $this->item(['name' => 'School shirt', 'category' => 'uniform', 'unit_price' => 4500], 1);
        $book = $this->item(['name' => 'Atlas', 'category' => 'book', 'unit_price' => 5000], 10);

        $this->as($this->admin)->postJson("{$this->host}/inventory/issues", [
            'student_id' => $this->ada->id,
            'lines' => [['item_id' => $book, 'quantity' => 1], ['item_id' => $shirt, 'quantity' => 2]],
        ])->assertStatus(409)->assertJsonFragment(['message' => 'Not enough stock. School shirt: 1 left, 2 needed.']);

        $this->assertSame(10, DB::table('inventory_items')->where('id', $book)->value('quantity_on_hand'));
        $this->assertSame(0, DB::table('inventory_issues')->count());
    }

    public function test_charges_wait_for_the_invoice_and_post_when_it_exists(): void
    {
        $this->switchOn();
        $shirt = $this->item(['name' => 'School shirt', 'category' => 'uniform', 'unit_price' => 4500]);
        $this->as($this->admin)->postJson("{$this->host}/inventory/issues", ['student_id' => $this->ada->id, 'lines' => [['item_id' => $shirt, 'quantity' => 1]]])->assertCreated();

        $this->as($this->admin)->getJson("{$this->host}/inventory/summary")->assertOk()
            ->assertJsonPath('sales.awaiting_invoice_count', 1)->assertJsonPath('awaiting_confirmation', 1);

        $invoice = $this->invoice(100000);
        $this->as($this->admin)->postJson("{$this->host}/inventory/charges/post", ['term_id' => $this->term->id])->assertOk()->assertJson(['posted' => 1, 'awaiting_invoice' => 0]);
        $this->as($this->admin)->postJson("{$this->host}/inventory/charges/post", ['term_id' => $this->term->id])->assertOk()->assertJson(['posted' => 0]);

        $this->assertEquals(104500, (float) $invoice->fresh()->total_amount);
    }

    public function test_the_parent_confirms_everything_received(): void
    {
        $this->switchOn();
        $shirt = $this->item(['name' => 'School shirt', 'category' => 'uniform', 'unit_price' => 4500]);
        $issue = $this->as($this->admin)->postJson("{$this->host}/inventory/issues", ['student_id' => $this->ada->id, 'lines' => [['item_id' => $shirt, 'quantity' => 1]]])->json('issue');

        $mine = $this->as($this->parent)->getJson("{$this->host}/parent/inventory")->assertOk()->assertJson(['enabled' => true, 'awaiting' => 1])->json('data.0');
        $this->assertSame('Ada Obi', $mine['student_name']);
        $this->assertArrayNotHasKey('admission_number', $mine);

        $this->as($this->otherParent)->postJson("{$this->host}/parent/inventory/issues/{$issue['id']}/confirm", ['lines' => [['id' => $issue['lines'][0]['id'], 'received' => true]]])->assertNotFound();
        $this->as($this->otherParent)->getJson("{$this->host}/parent/inventory")->assertJson(['data' => []]);

        $this->as($this->parent)->postJson("{$this->host}/parent/inventory/issues/{$issue['id']}/confirm", ['lines' => [['id' => $issue['lines'][0]['id'], 'received' => true]]])
            ->assertOk()->assertJsonPath('issue.status', 'confirmed');
        $this->as($this->parent)->postJson("{$this->host}/parent/inventory/issues/{$issue['id']}/confirm", ['lines' => [['id' => $issue['lines'][0]['id'], 'received' => true]]])
            ->assertStatus(409);
    }

    public function test_a_missing_item_needs_a_note_and_tells_the_school(): void
    {
        $this->switchOn();
        $shirt = $this->item(['name' => 'School shirt', 'category' => 'uniform', 'unit_price' => 4500]);
        $book = $this->item(['name' => 'Atlas', 'category' => 'book', 'unit_price' => 5000]);
        $issue = $this->as($this->admin)->postJson("{$this->host}/inventory/issues", ['student_id' => $this->ada->id, 'lines' => [['item_id' => $shirt, 'quantity' => 1], ['item_id' => $book, 'quantity' => 1]]])->json('issue');
        [$l1, $l2] = array_column($issue['lines'], 'id');
        $url = "{$this->host}/parent/inventory/issues/{$issue['id']}/confirm";

        $this->as($this->parent)->postJson($url, ['lines' => [['id' => $l1, 'received' => true]]])->assertStatus(409); // must answer every line
        $this->as($this->parent)->postJson($url, ['lines' => [['id' => $l1, 'received' => true], ['id' => $l2, 'received' => false]]])->assertStatus(409); // needs a note

        $this->as($this->parent)->postJson($url, ['lines' => [['id' => $l1, 'received' => true], ['id' => $l2, 'received' => false]], 'note' => 'No atlas in her bag'])
            ->assertOk()->assertJsonPath('issue.status', 'disputed');

        $this->assertTrue(DB::table('notification_logs')->where('user_id', $this->admin->id)->where('category', 'inventory')->exists());
        $this->as($this->admin)->getJson("{$this->host}/inventory/issues?status=disputed")->assertOk()
            ->assertJsonPath('data.0.parent_note', 'No atlas in her bag')->assertJsonPath('data.0.has_parent', true);
    }

    public function test_redelivered_sends_the_missing_items_back_to_the_parent(): void
    {
        [$issue, $l1, $l2] = $this->disputedIssue();

        $this->as($this->admin)->postJson("{$this->host}/inventory/issues/{$issue}/resolve", ['resolution' => 'redelivered', 'note' => 'Given to her in class'])
            ->assertOk()->assertJsonPath('issue.status', 'awaiting');

        $this->as($this->parent)->postJson("{$this->host}/parent/inventory/issues/{$issue}/confirm", ['lines' => [['id' => $l2, 'received' => true]]])
            ->assertOk()->assertJsonPath('issue.status', 'confirmed');
    }

    public function test_cancelling_a_missing_item_takes_it_off_the_invoice_and_back_into_stock(): void
    {
        $invoice = $this->invoice(100000);
        [$issue, , , $book] = $this->disputedIssue();
        $this->assertEquals(109500, (float) $invoice->fresh()->total_amount);

        $this->as($this->admin)->postJson("{$this->host}/inventory/issues/{$issue}/resolve", ['resolution' => 'cancelled'])
            ->assertOk()->assertJsonPath('issue.status', 'resolved')->assertJsonPath('issue.charged_total', 4500);

        $this->assertEquals(104500, (float) $invoice->fresh()->total_amount);
        $this->assertSame(10, DB::table('inventory_items')->where('id', $book)->value('quantity_on_hand'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'invoice.store_item_removed')->exists());
    }

    public function test_an_item_already_paid_for_cannot_be_cancelled_until_finance_refunds_it(): void
    {
        $invoice = $this->invoice(100000, 0);
        [$issue] = $this->disputedIssue();
        $invoice->update(['amount_paid' => 109500, 'status' => 'paid']);

        $this->as($this->admin)->postJson("{$this->host}/inventory/issues/{$issue}/resolve", ['resolution' => 'cancelled'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'Store: Atlas is already paid for. Record the refund in Finance, then cancel it here.']);

        $this->assertEquals(109500, (float) $invoice->fresh()->total_amount);
        $this->assertSame('disputed', DB::table('inventory_issues')->where('id', $issue)->value('status'));
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} issue, received line, missing line, book item */
    private function disputedIssue(): array
    {
        $this->switchOn();
        $shirt = $this->item(['name' => 'School shirt', 'category' => 'uniform', 'unit_price' => 4500]);
        $book = $this->item(['name' => 'Atlas', 'category' => 'book', 'unit_price' => 5000]);
        $issue = $this->as($this->admin)->postJson("{$this->host}/inventory/issues", ['student_id' => $this->ada->id, 'lines' => [['item_id' => $shirt, 'quantity' => 1], ['item_id' => $book, 'quantity' => 1]]])->json('issue');
        [$l1, $l2] = array_column($issue['lines'], 'id');
        $this->as($this->parent)->postJson("{$this->host}/parent/inventory/issues/{$issue['id']}/confirm", ['lines' => [['id' => $l1, 'received' => true], ['id' => $l2, 'received' => false]], 'note' => 'No atlas'])->assertOk();

        return [$issue['id'], $l1, $l2, $book];
    }
}
