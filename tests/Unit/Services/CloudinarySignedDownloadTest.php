<?php

namespace Tests\Unit\Services;

use App\Services\Settlement\Receipts\CloudinaryClient;
use Tests\TestCase;

/**
 * إثبات أن رابط التنزيل الموقّع يحمل انتهاء صلاحية حقيقيًا **مرتبطًا بالتوقيع**.
 *
 * لماذا هذا مهم: توقيع URL وحده لا يعني أن الرابط مؤقت. الحماية الفعلية في
 * Cloudinary قائمة على أن `expires_at` جزء من النص الموقّع — أي تعديل عليه
 * يُبطل التوقيع، وأي استخدام بعد الوقت المحدد يرفضه الخادم.
 *
 * الاختبار يعمل بمفاتيح وهمية تمامًا (لا شبكة، لا حساب): يولّد رابطين بفارق
 * ثانية واحدة في `expires_at` ويثبت أن التوقيع يتغيّر. لو كان `expires_at`
 * خارج التوقيع لكان التوقيعان متطابقين.
 */
class CloudinarySignedDownloadTest extends TestCase
{
    private function client(): CloudinaryClient
    {
        config([
            'services.cloudinary.cloud' => 'demo-cloud',
            'services.cloudinary.receipts.api_key' => 'demo-key',
            'services.cloudinary.receipts.api_secret' => 'demo-secret',
        ]);

        return app(CloudinaryClient::class);
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_map('strval', $query);
    }

    private function sign(int $expiresAt): string
    {
        return $this->client()->signedDownloadUrl('daway/settlement-receipts/receipt-1', 'pdf', [
            'type' => 'authenticated',
            'resource_type' => 'raw',
            'attachment' => true,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_the_signed_url_carries_an_expiry_timestamp(): void
    {
        $query = $this->queryOf($this->sign(1800000000));

        $this->assertArrayHasKey('expires_at', $query);
        $this->assertSame('1800000000', $query['expires_at']);
        $this->assertArrayHasKey('signature', $query);
        $this->assertNotSame('', $query['signature']);
    }

    public function test_the_expiry_is_part_of_the_signed_payload_not_just_a_query_parameter(): void
    {
        $first = $this->queryOf($this->sign(1800000000));
        $second = $this->queryOf($this->sign(1800000001));

        // نفس الأصل، نفس النوع، نفس المفتاح — الفرق الوحيد ثانية واحدة في
        // expires_at. تغيّر التوقيع = الانتهاء داخل التوقيع فعلًا.
        $this->assertNotSame($first['signature'], $second['signature']);
    }

    public function test_the_url_targets_the_private_download_endpoint_for_the_right_resource_type(): void
    {
        $url = $this->sign(1800000000);

        $this->assertStringContainsString('/v1_1/demo-cloud/raw/download', $url);
        $this->assertStringContainsString('type=authenticated', $url);
    }

    public function test_the_api_secret_never_appears_in_the_generated_url(): void
    {
        $url = $this->sign(1800000000);

        $this->assertStringNotContainsString('demo-secret', $url);
        $this->assertArrayNotHasKey('api_secret', $this->queryOf($url));
    }
}
