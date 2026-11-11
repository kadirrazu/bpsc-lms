<?php

namespace Tests\Feature;

use App\Console\Questions\AdminChoiceQuestion;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Category;
use App\Models\Designation;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LibraryReportsTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function book(array $attributes = []): Book
    {
        $publisher = Publisher::firstOrCreate(['title_bn' => 'প্রকাশক']);

        return Book::create($attributes + [
            'accession_number' => '000123', 'title_bn' => 'বাংলা গ্রন্থ',
            'publisher_id' => $publisher->id, 'call_number' => '800',
            'number_of_copies' => 3, 'price' => '25.50', 'collection_method' => 'Gift',
        ]);
    }

    public function test_report_ui_and_export_routes_are_restricted_to_administrators_and_librarians(): void
    {
        $this->get('/reports')->assertRedirect(route('login'));
        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $this->login($role);
            $this->get('/reports')->assertForbidden();
            $this->get('/reports/members/export/pdf')->assertForbidden();
            $this->get('/reports/members/export/xlsx')->assertForbidden();
            $this->get('/reports/member-loans?member=1')->assertForbidden();
            $this->get('/reports/member-loans/export/pdf?member=1')->assertForbidden();
        }

        foreach ([UserRole::Admin, UserRole::Librarian] as $role) {
            $this->login($role);
            foreach (['circulation', 'acquisitions', 'members', 'categories', 'open-loans', 'popular', 'annual'] as $report) {
                $this->get('/reports/'.$report)->assertOk()->assertSee('Download PDF')->assertSee('Download Excel');
            }
        }
        $this->get('/reports')->assertOk()->assertSeeInOrder(['Catalog Setup', 'Reports', 'Administration']);
        $this->get('/reports/unknown')->assertNotFound();
        $this->get('/reports/circulation?from=2026-11-03&to=2026-11-02')->assertSessionHasErrors('to');
    }

    public function test_visible_header_shows_the_designation_for_staff_and_members(): void
    {
        foreach ([UserRole::Librarian, UserRole::Member] as $role) {
            $user = $this->login($role);
            $user->update(['designation_id' => Designation::where('name', 'Librarian')->firstOrFail()->id]);
            $this->get($role === UserRole::Member ? '/member' : '/dashboard')
                ->assertOk()->assertSee('<strong class="d-block">'.e($user->name).'</strong>', false)
                ->assertSee('fw-normal text-secondary">Librarian</span>', false);
        }
    }

    public function test_admin_choice_keeps_numeric_menu_without_terminal_autocomplete(): void
    {
        $question = new AdminChoiceQuestion('Designation', ['Librarian', 'Director'], 0);

        $this->assertNull($question->getAutocompleterValues());
        $this->assertSame('Director', ($question->getValidator())('1'));
    }

    public function test_issue_and_return_periods_are_independent_and_end_date_is_inclusive(): void
    {
        $this->login(UserRole::Librarian);
        $book = $this->book();
        DB::table('book_loans')->insert([
            ['book_id' => $book->id, 'quantity' => 2, 'issued_at' => '2026-10-29 12:00:00', 'returned_at' => '2026-11-02 23:59:59'],
            ['book_id' => $book->id, 'quantity' => 1, 'issued_at' => '2026-11-02 18:00:00', 'returned_at' => '2026-11-03 00:00:00'],
        ]);

        $page = $this->get('/reports/circulation?from=2026-11-02&to=2026-11-02')->assertOk();
        $this->assertSame(['Books Issued' => 1, 'Books Returned' => 2], $page->viewData('summary'));
        $this->assertSame('2026-11-02', $page->viewData('rows')->first()->period);
        $this->get('/reports/popular?from=2026-11-02&to=2026-11-02')->assertOk()
            ->assertViewHas('summary', ['Titles issued' => 1, 'Books Issued' => 1]);
        $annualPage = $this->get('/reports/annual?year=2026')->assertOk()->assertSee('<tfoot>', false);
        $this->assertEquals(3, $annualPage->viewData('totals')['issued']);
        $this->assertEquals(3, $annualPage->viewData('totals')['returned']);
        $annual = $annualPage->viewData('rows');
        $this->assertCount(12, $annual);
        $this->assertEquals(2, $annual[9]->issued);
        $this->assertEquals(3, $annual[10]->returned);
    }

    public function test_inventory_and_acquisition_counts_handle_multi_category_and_missing_prices(): void
    {
        $this->login(UserRole::Admin);
        $book = $this->book();
        $book->forceFill(['created_at' => '2026-11-01 00:00:00'])->save();
        $categories = Category::limit(2)->pluck('id');
        $book->categories()->attach($categories);
        $unpriced = $this->book(['accession_number' => '000124', 'price' => null]);
        $unpriced->forceFill(['created_at' => '2026-11-02 23:59:59'])->save();
        $unpriced->delete();
        $page = $this->get('/reports/acquisitions?from=2026-11-01&to=2026-11-02&method=Gift')->assertOk();
        $this->assertSame(['Titles added' => 2, 'Recorded copies' => 6, 'Known value (BDT)' => '76.50', 'Titles without price' => 1], $page->viewData('summary'));
        $inventory = $this->get('/reports/categories')->assertOk();
        $this->assertSame(1, $inventory->viewData('summary')['Distinct catalog titles']);
        $this->assertEquals(2, $inventory->viewData('rows')->sum('titles'));
        $this->assertEquals(1, $inventory->viewData('inventoryTotals')->titles);
        $this->assertEquals(3, $inventory->viewData('inventoryTotals')->copies);
        $this->assertEquals(2, $inventory->viewData('totals')['titles']);
        $this->assertEquals(6, $inventory->viewData('totals')['copies']);
        $inventory->assertSee('Total across categories');
        $category = $this->get('/reports/categories?category='.$categories->first())->assertOk();
        $this->assertSame('000123', $category->viewData('rows')->first()->accession);
    }

    public function test_member_status_and_overdue_filters_reflect_current_records(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11 12:00:00'));
        $this->login(UserRole::Admin);
        $member = User::factory()->create(['role' => UserRole::Member, 'is_active' => false]);
        User::factory()->create(['role' => UserRole::Member]);
        $book = $this->book();
        DB::table('book_loans')->insert([
            ['book_id' => $book->id, 'user_id' => $member->id, 'quantity' => 2, 'issued_at' => '2026-10-20 12:00:00', 'due_at' => '2026-11-04 12:00:00', 'returned_at' => null],
            ['book_id' => $book->id, 'user_id' => $member->id, 'quantity' => 1, 'issued_at' => '2026-11-02 12:00:00', 'due_at' => '2026-11-17 12:00:00', 'returned_at' => null],
            ['book_id' => $book->id, 'user_id' => $member->id, 'quantity' => 1, 'issued_at' => '2026-10-20 12:00:00', 'due_at' => '2026-11-04 12:00:00', 'returned_at' => '2026-11-06 08:00:00'],
        ]);

        $roster = $this->get('/reports/members?status=inactive')->assertOk();
        $this->assertSame(['Members' => 1, 'Active' => 0, 'Inactive' => 1], $roster->viewData('summary'));
        $this->assertEquals(3, $roster->viewData('rows')->first()->open_copies);
        $this->assertEquals(4, $roster->viewData('rows')->first()->borrowed_copies);
        $this->assertEquals(1, $roster->viewData('rows')->first()->returned_copies);
        $roster->assertSee('bg-red-lt">Inactive', false)
            ->assertSee(route('reports.index', ['report' => 'member-loans', 'member' => $member->id]));
        $history = $this->get('/reports/member-loans?member='.$member->id)->assertOk()
            ->assertSee($member->name)->assertSee('Returned')->assertSee('Overdue')->assertSee('On Loan');
        $this->assertSame(['Copies Borrowed' => 4, 'Copies Returned' => 1, 'Copies on Loan' => 3], $history->viewData('summary'));
        $this->assertCount(3, $history->viewData('rows'));
        $this->get('/reports/member-loans?member=999999')->assertNotFound();
        $this->get('/reports/open-loans?scope=overdue')->assertOk()
            ->assertViewHas('summary', ['Open loans' => 1, 'Copies on loan' => 2, 'Overdue loans' => 1]);
    }

    public function test_annual_custom_period_uses_inclusive_date_bounds_and_lists_every_month(): void
    {
        $this->login(UserRole::Admin);
        $book = $this->book(['price' => 10.25, 'number_of_copies' => 3]);
        $book->forceFill(['created_at' => '2025-07-01 00:00:00'])->save();
        DB::table('book_loans')->insert([
            ['book_id' => $book->id, 'quantity' => 2, 'issued_at' => '2025-06-30 23:59:59', 'returned_at' => '2025-07-01 00:00:00'],
            ['book_id' => $book->id, 'quantity' => 1, 'issued_at' => '2026-06-30 23:59:59', 'returned_at' => '2026-07-01 00:00:00'],
        ]);

        $range = '?from=2025-07-01&to=2026-06-30';
        $report = $this->get('/reports/annual'.$range)->assertOk();
        $rows = $report->viewData('rows');
        $this->assertCount(12, $rows);
        $this->assertSame('Jul 2025', $rows->first()->period);
        $this->assertSame('Jun 2026', $rows->last()->period);
        $this->assertEquals(2, $rows->first()->returned);
        $this->assertEquals(1, $rows->last()->issued);
        $this->assertSame(1, $report->viewData('summary')['Books Issued']);
        $this->assertSame(2, $report->viewData('summary')['Books Returned']);
        $this->assertEquals(['period' => 'Total', 'issued' => 1, 'returned' => 2, 'titles' => 1, 'copies' => 3, 'value' => 30.75, 'members' => 0], $report->viewData('totals'));
        $report->assertSee('from=2025-07-01&amp;to=2026-06-30', false);
        $this->get('/reports/annual?from=2025-07-01')->assertSessionHasErrors('to');
        $this->get('/reports/annual?from=2026-07-01&to=2025-06-30')->assertSessionHasErrors('to');
        $this->get('/reports/annual/export/pdf'.$range)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_exports_retain_report_filters_and_excel_keeps_identifiers_as_text(): void
    {
        $this->login(UserRole::Librarian);
        $this->book(['title_bn' => '=1+2']);
        $this->book(['accession_number' => '000124', 'collection_method' => 'Purchase']);

        $pdf = $this->get('/reports/acquisitions/export/pdf?method=Gift')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $response = $this->get('/reports/acquisitions/export/xlsx?method=Gift')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'lms-report-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::createReader('Xlsx')->load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame('000123', $sheet->getCell('B8')->getValue());
            $this->assertSame('=1+2', $sheet->getCell('C8')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C8')->getDataType());
            $this->assertEquals(76.50, $sheet->getCell('G8')->getValue());
            $this->assertSame(8, $sheet->getHighestDataRow());
            foreach (['B7', 'B8', 'E7', 'E8', 'F7', 'F8', 'G7', 'G8', 'H7', 'H8'] as $cell) {
                $this->assertSame('center', $sheet->getStyle($cell)->getAlignment()->getHorizontal());
                $this->assertSame('center', $sheet->getStyle($cell)->getAlignment()->getVertical());
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.exported']);
    }
}
