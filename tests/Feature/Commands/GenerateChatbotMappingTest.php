<?php

namespace Tests\Feature\Commands;

use App\Support\MedicineNameMapper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateChatbotMappingTest extends TestCase
{
    private string $fixturePath;

    private string $outPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = storage_path('app/private/chatbot_mapping_fixture.json');
        $this->outPath = storage_path('app/private/chatbot_mapping_out.json');

        File::delete([$this->fixturePath, $this->outPath]);
    }

    protected function tearDown(): void
    {
        File::delete([$this->fixturePath, $this->outPath]);

        parent::tearDown();
    }

    public function test_generates_mapping_file_from_moh_catalog(): void
    {
        file_put_contents($this->fixturePath, json_encode([
            [
                'trade_name' => 'Panadol Extra 500 mg',
                'generic_name' => 'Paracetamol',
                'moh_drug_id' => 200,
                'moh_product_id' => 100,
                'product_class' => 'Human Drug',
            ],
            [
                'trade_name' => null,
                'generic_name' => null,
                'moh_drug_id' => null,
                'moh_product_id' => null,
                'product_class' => null,
            ],
            [
                'trade_name' => 'Augmentin 1g',
                'generic_name' => 'Amoxicillin & Clavulanic Acid',
                'moh_drug_id' => 201,
                'moh_product_id' => 101,
                'product_class' => 'Human Drug',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('chatbot:mapping', ['--file' => $this->fixturePath, '--out' => $this->outPath])
            ->assertExitCode(0);

        $this->assertFileExists($this->outPath);

        $entries = json_decode((string) file_get_contents($this->outPath), true);
        $this->assertIsArray($entries);
        // السجل بدون trade_name يُتخطى
        $this->assertCount(2, $entries);

        [$panadol, $augmentin] = $entries;

        $this->assertSame(1, $panadol['id']);
        $this->assertSame('Panadol Extra 500 mg', $panadol['name_en']);
        $this->assertSame(200, $panadol['moh_drug_id']);
        // التحويل الصوتي يشمل الجرعة أيضاً (مطابقة تقريبية مقصودة) — بمسافات موحّدة
        $this->assertSame('بانادول اكسترا 500 مج', $panadol['name_ar']);
        $this->assertContains('paracetamol', $panadol['aliases']);
        $this->assertContains('panadol extra', $panadol['aliases']);
        $this->assertNotContains('500 mg', $panadol['aliases']);

        $this->assertSame(2, $augmentin['id']);
        $this->assertTrue(str_starts_with((string) $augmentin['name_ar'], 'اوجمينتين'));
        // aliases[0] هو الاسم الكامل lowercase — والاسم الأساسي المنظف موجود ضمن القائمة
        $this->assertSame('augmentin 1g', $augmentin['aliases'][0]);
        $this->assertContains('augmentin', $augmentin['aliases']);
    }

    public function test_fails_when_source_file_missing(): void
    {
        $this->artisan('chatbot:mapping', ['--file' => storage_path('app/private/does_not_exist.json')])
            ->assertExitCode(1);
    }

    public function test_transliteration_of_known_brand_names(): void
    {
        $this->assertSame('بانادول', MedicineNameMapper::toArabic('Panadol'));
        $this->assertSame('فولتارين', MedicineNameMapper::toArabic('Voltaren'));
        $this->assertSame('ايبوبروفين', MedicineNameMapper::toArabic('Ibuprofen'));
        $this->assertSame('اسبيرين', MedicineNameMapper::toArabic('Aspirin'));

        // النص العربي الموجود مسبقاً يبقى كما هو
        $this->assertSame('بانادول اكسترا بنادول', MedicineNameMapper::toArabic('Panadol Extra بنادول'));
    }
}
