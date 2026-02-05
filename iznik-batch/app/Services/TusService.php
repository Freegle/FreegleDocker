<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for uploading files to tusd (TUS protocol).
 *
 * TUS (Tus Upload Server) is a resumable upload protocol used by Freegle
 * to store message and chat attachments.
 *
 * Port from iznik-server/include/misc/Tus.php
 */
class TusService
{
    private string $tusUploader;

    public function __construct(?string $tusUploader = null)
    {
        $this->tusUploader = $tusUploader ?? config('freegle.tus_uploader');
    }

    /**
     * Upload image data to tusd and return the URL.
     *
     * @param  string  $data  Binary image data
     * @param  string  $mime  MIME type (default: image/webp)
     * @return string|null URL of uploaded file, or null on failure
     */
    public function upload(string $data, string $mime = 'image/webp'): ?string
    {
        $chkAlgo = 'crc32';
        $fileChk = base64_encode(hash($chkAlgo, $data, true));
        $fileLen = strlen($data);

        // Step 1: Create the file (POST request)
        $url = $this->createFile($fileLen, $mime);
        if (! $url) {
            return null;
        }

        // Step 2: Verify file exists (HEAD request)
        if (! $this->verifyFile($url)) {
            return null;
        }

        // Step 3: Upload the data (PATCH request)
        return $this->uploadData($url, $data, $chkAlgo, $fileChk);
    }

    /**
     * Create a new file on the TUS server.
     */
    private function createFile(int $fileLen, string $mime): ?string
    {
        try {
            $response = Http::withHeaders([
                'Tus-Resumable' => '1.0.0',
                'Content-Type' => 'application/offset+octet-stream',
                'Upload-Length' => (string) $fileLen,
                'Upload-Metadata' => sprintf(
                    'relativePath bnVsbA==,name %s,type %s,filetype %s,filename %s',
                    base64_encode($mime),
                    base64_encode('image/webp'),
                    base64_encode('image/webp'),
                    base64_encode('image.webp')
                ),
            ])->post($this->tusUploader);

            if ($response->status() === 201) {
                $location = $response->header('Location');
                if ($location) {
                    return $location;
                }
            }

            Log::warning('TUS create file failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('TUS create file exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verify file exists on the TUS server.
     */
    private function verifyFile(string $url): bool
    {
        try {
            $response = Http::withHeaders([
                'Tus-Resumable' => '1.0.0',
                'Content-Type' => 'application/offset+octet-stream',
            ])->head($url);

            if ($response->status() === 200) {
                return true;
            }

            Log::warning('TUS verify file failed', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('TUS verify file exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Upload file data to the TUS server.
     */
    private function uploadData(string $url, string $data, string $chkAlgo, string $fileChk): ?string
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/offset+octet-stream',
                'Tus-Resumable' => '1.0.0',
                'Upload-Offset' => '0',
                'Upload-Checksum' => "$chkAlgo $fileChk",
            ])->withBody($data, 'application/offset+octet-stream')
                ->patch($url);

            if ($response->status() === 200 || $response->status() === 204) {
                return $url;
            }

            Log::warning('TUS upload data failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('TUS upload data exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the external UID format for a TUS URL.
     *
     * @param  string  $url  TUS upload URL
     * @return string UID in format 'freegletusd-{id}'
     */
    public static function urlToExternalUid(string $url): string
    {
        return 'freegletusd-'.basename($url);
    }
}
