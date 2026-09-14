<?php

namespace Tests\Unit\Services\InventoryImport;

use App\Services\InventoryImport\InventoryFileParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * اختبارات قارئ ملف الاستيراد — كل المدخلات غير الموثوقة التي قد تصل من ملف.
 *
 * لا نستخدم WithHeadingRow في القارئ، ولا نعتمد على أسماء أعمدة صارمة:
 * الاختبارات هنا تثبّت هذا السلوك حتى لا يُكسر لاحقاً بصمت.
 */
class InventoryFileParserTest extends TestCase
{
    private InventoryFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = app(InventoryFileParser::class);
    }

    /** يكتب CSV مؤقتاً ويعيد مساره المطلق. */
    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pi_').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_it_parses_a_valid_file_into_normalized_rows(): void
    {
        $path = $this->csv(
            "trade_name,trade_name_ar,active_ingredient,price,quantity,min_stock,is_available\n".
            "Panadol,بانادول,Paracetamol,12.50,40,5,1\n"
        );

        $result = $this->parser->parse($path);

        $this->assertCount(1, $result['rows']);
        $this->assertSame([], $result['unknown_columns']);

        $row = $result['rows'][0];

        // الرأس سطر 1 في Excel ⇒ أول سطر بيانات = 2
        $this->assertSame(2, $row['row']);
        $this->assertSame('Panadol', $row['input']['trade_name']);
        $this->assertSame('بانادول', $row['input']['trade_name_ar']);
        $this->assertSame('Paracetamol', $row['input']['active_ingredient']);
        $this->assertSame(12.5, $row['input']['price']);
        $this->assertSame(40, $row['input']['quantity']);
        $this->assertSame(5, $row['input']['min_stock']);
        $this->assertTrue($row['input']['is_available']);
        $this->assertSame([], $row['errors']);
        $this->assertSame([], $row['warnings']);

        @unlink($path);
    }

    public function test_it_normalizes_arabic_indic_digits_in_numbers(): void
    {
        $path = $this->csv(
            "trade_name,price,quantity\n".
            "Panadol,١٢.٥,٤٠\n"
        );

        $result = $this->parser->parse($path);
        $row = $result['rows'][0];

        $this->assertSame(12.5, $row['input']['price']);
        $this->assertSame(40, $row['input']['quantity']);
        $this->assertSame([], $row['errors']);

        @unlink($path);
    }

    public function test_it_strips_the_utf8_bom_from_the_first_header_cell(): void
    {
        $path = $this->csv("\xEF\xBB\xBF".'trade_name,price,quantity'."\n".'Panadol,10,5'."\n");

        $result = $this->parser->parse($path);

        $this->assertSame('Panadol', $result['rows'][0]['input']['trade_name']);

        @unlink($path);
    }

    public function test_it_skips_blank_rows_and_keeps_excel_row_numbers(): void
    {
        $path = $this->csv(
            "trade_name,price,quantity\n".
            "A,1,1\n".
            ",,\n".
            "B,2,2\n"
        );

        $result = $this->parser->parse($path);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(2, $result['rows'][0]['row']);
        // السطر الفارغ محسوب في الترقيم كما يراه المستخدم في Excel
        $this->assertSame(4, $result['rows'][1]['row']);

        @unlink($path);
    }

    public function test_it_reports_unknown_columns_and_ignores_them(): void
    {
        $path = $this->csv(
            "trade_name,price,quantity,notes,internal_code\n".
            "A,1,1,ملاحظة,X9\n"
        );

        $result = $this->parser->parse($path);

        $this->assertSame(['notes', 'internal_code'], $result['unknown_columns']);
        $this->assertCount(1, $result['rows']);

        @unlink($path);
    }

    public function test_it_tolerates_leading_rows_before_the_header(): void
    {
        $path = $this->csv(
            "تقرير مخزون\n".
            "صيدلية الشفاء\n".
            "trade_name,price,quantity\n".
            "A,1,7\n"
        );

        $result = $this->parser->parse($path);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(4, $result['rows'][0]['row']);

        @unlink($path);
    }

    public function test_it_rejects_a_file_without_a_recognizable_header(): void
    {
        $path = $this->csv("foo,bar\n1,2\n");

        $this->expectException(ValidationException::class);

        try {
            $this->parser->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_a_file_missing_required_columns(): void
    {
        $path = $this->csv("trade_name,price\nA,1\n");

        $this->expectException(ValidationException::class);

        try {
            $this->parser->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_a_file_with_no_data_rows(): void
    {
        $path = $this->csv("trade_name,price,quantity\n");

        $this->expectException(ValidationException::class);

        try {
            $this->parser->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_marks_a_negative_quantity_as_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,1,-5\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('invalid_quantity', $row['errors']);

        @unlink($path);
    }

    public function test_it_marks_a_fractional_quantity_as_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,1,2.5\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('invalid_quantity', $row['errors']);

        @unlink($path);
    }

    public function test_it_marks_a_blank_quantity_as_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,1,\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('invalid_quantity', $row['errors']);

        @unlink($path);
    }

    public function test_a_blank_price_becomes_zero_with_a_warning_not_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,,3\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertContains('missing_price', $row['warnings']);
        $this->assertSame(0.0, $row['input']['price']);

        @unlink($path);
    }

    public function test_it_marks_a_negative_price_as_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,-1,3\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('invalid_price', $row['errors']);

        @unlink($path);
    }

    public function test_it_requires_at_least_one_name(): void
    {
        $path = $this->csv("trade_name,trade_name_ar,price,quantity\n,,1,3\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('missing_name', $row['errors']);

        @unlink($path);
    }

    public function test_it_accepts_an_arabic_only_name(): void
    {
        $path = $this->csv("trade_name,trade_name_ar,price,quantity\n,بانادول,1,3\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertSame('بانادول', $row['input']['trade_name_ar']);

        @unlink($path);
    }

    public function test_it_parses_availability_booleans_in_arabic_and_english(): void
    {
        $path = $this->csv(
            "trade_name,price,quantity,is_available\n".
            "A,1,1,نعم\n".
            "B,1,1,لا\n".
            "C,1,1,yes\n".
            "D,1,1,0\n"
        );

        $rows = $this->parser->parse($path)['rows'];

        $this->assertTrue($rows[0]['input']['is_available']);
        $this->assertFalse($rows[1]['input']['is_available']);
        $this->assertTrue($rows[2]['input']['is_available']);
        $this->assertFalse($rows[3]['input']['is_available']);

        @unlink($path);
    }

    public function test_it_marks_an_unrecognized_availability_value_as_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity,is_available\nA,1,1,maybe\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('invalid_is_available', $row['errors']);

        @unlink($path);
    }

    public function test_it_marks_a_non_integer_min_stock_as_an_error(): void
    {
        $path = $this->csv("trade_name,price,quantity,min_stock\nA,1,1,3.5\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertContains('invalid_min_stock', $row['errors']);

        @unlink($path);
    }

    public function test_it_leaves_min_stock_null_when_absent(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,1,1\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertNull($row['input']['min_stock']);
        $this->assertSame([], $row['errors']);

        @unlink($path);
    }

    public function test_it_defaults_availability_from_quantity_when_absent(): void
    {
        $path = $this->csv("trade_name,price,quantity\nA,1,0\nB,1,4\n");

        $rows = $this->parser->parse($path)['rows'];

        $this->assertFalse($rows[0]['input']['is_available']);
        $this->assertTrue($rows[1]['input']['is_available']);

        @unlink($path);
    }

    public function test_it_rejects_a_file_exceeding_the_row_limit(): void
    {
        config(['inventory_import.max_rows' => 3]);

        $csv = "trade_name,price,quantity\n";

        for ($i = 0; $i < 5; $i++) {
            $csv .= "M{$i},1,1\n";
        }

        $path = $this->csv($csv);

        $this->expectException(ValidationException::class);

        try {
            $this->parser->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_duplicate_headers_keep_the_first_column(): void
    {
        $path = $this->csv("trade_name,price,quantity,price\nA,11,3,99\n");

        $row = $this->parser->parse($path)['rows'][0];

        // أول عمود price يفوز — لا استبدال صامت
        $this->assertSame(11.0, $row['input']['price']);

        @unlink($path);
    }

    public function test_it_detects_a_semicolon_delimited_file(): void
    {
        $path = $this->csv("trade_name;price;quantity\nA;4.5;9\n");

        $row = $this->parser->parse($path)['rows'][0];

        $this->assertSame('A', $row['input']['trade_name']);
        $this->assertSame(4.5, $row['input']['price']);
        $this->assertSame(9, $row['input']['quantity']);

        @unlink($path);
    }

    public function test_it_detects_a_semicolon_file_that_starts_with_a_title_row(): void
    {
        // الحالة التي تكسر التخمين الافتراضي: سطر عنوان بلا فواصل قبل الرأس
        $path = $this->csv("تقرير مخزون\nصيدلية الشفاء\ntrade_name;price;quantity\nA;2;3\n");

        $result = $this->parser->parse($path);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('A', $result['rows'][0]['input']['trade_name']);
        $this->assertSame(3, $result['rows'][0]['input']['quantity']);

        @unlink($path);
    }

    public function test_a_comma_inside_a_data_value_does_not_beat_the_real_delimiter(): void
    {
        $path = $this->csv(
            "trade_name;price;quantity\n".
            "Panadol, Extra;5;2\n".
            "Aspirin;6;3\n"
        );

        $rows = $this->parser->parse($path)['rows'];

        $this->assertSame('Panadol, Extra', $rows[0]['input']['trade_name']);
        $this->assertSame(5.0, $rows[0]['input']['price']);
        $this->assertSame(2, $rows[0]['input']['quantity']);

        @unlink($path);
    }
}
