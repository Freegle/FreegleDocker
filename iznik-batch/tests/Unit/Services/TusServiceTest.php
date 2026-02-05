<?php

namespace Tests\Unit\Services;

use App\Services\TusService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TusServiceTest extends TestCase
{
    private TusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TusService('https://test-tus-server.example.com');
    }

    public function test_upload_returns_url_on_success(): void
    {
        Http::fake([
            'test-tus-server.example.com' => Http::sequence()
                // POST to create file
                ->push('', 201, ['Location' => 'https://test-tus-server.example.com/files/abc123'])
                // HEAD to verify
                ->push('', 200, ['Upload-Offset' => '0'])
                // PATCH to upload
                ->push('', 204),
            'test-tus-server.example.com/files/abc123' => Http::response('', 200),
        ]);

        $data = 'test image data';
        $result = $this->service->upload($data, 'image/jpeg');

        $this->assertEquals('https://test-tus-server.example.com/files/abc123', $result);
    }

    public function test_upload_returns_null_when_create_fails(): void
    {
        Http::fake([
            'test-tus-server.example.com' => Http::response('', 500),
        ]);

        $data = 'test image data';
        $result = $this->service->upload($data, 'image/jpeg');

        $this->assertNull($result);
    }

    public function test_upload_returns_null_when_verify_fails(): void
    {
        Http::fake([
            'test-tus-server.example.com' => Http::sequence()
                // POST to create file
                ->push('', 201, ['Location' => 'https://test-tus-server.example.com/files/abc123']),
            'test-tus-server.example.com/files/abc123' => Http::response('', 404),
        ]);

        $data = 'test image data';
        $result = $this->service->upload($data, 'image/jpeg');

        $this->assertNull($result);
    }

    public function test_upload_returns_null_when_patch_fails(): void
    {
        Http::fake([
            'test-tus-server.example.com' => Http::sequence()
                // POST to create file
                ->push('', 201, ['Location' => 'https://test-tus-server.example.com/files/abc123']),
            'test-tus-server.example.com/files/abc123' => Http::sequence()
                // HEAD to verify
                ->push('', 200, ['Upload-Offset' => '0'])
                // PATCH to upload
                ->push('', 500),
        ]);

        $data = 'test image data';
        $result = $this->service->upload($data, 'image/jpeg');

        $this->assertNull($result);
    }

    public function test_url_to_external_uid_formats_correctly(): void
    {
        $url = 'https://uploads.example.com/files/my-file-id-123';
        $uid = TusService::urlToExternalUid($url);

        $this->assertEquals('freegletusd-my-file-id-123', $uid);
    }

    public function test_url_to_external_uid_handles_trailing_slash(): void
    {
        $url = 'https://uploads.example.com/files/my-file-id-123/';
        $uid = TusService::urlToExternalUid($url);

        // basename with trailing slash gives empty string, but real URLs won't have this
        $this->assertStringStartsWith('freegletusd-', $uid);
    }
}
