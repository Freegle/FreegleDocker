<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Service for uploading files to tusd (TUS protocol).
 *
 * TUS (Tus Upload Server) is a resumable upload protocol used by Freegle
 * to store message and chat attachments.
 *
 * Port from iznik-server/include/misc/Tus.php - uses curl throughout
 * for precise control over the TUS protocol.
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
        if (! $this->verifyFileExists($url)) {
            return null;
        }

        // Step 3: Upload the data (PATCH request) - always from offset 0 for fresh uploads
        return $this->uploadData($url, $data, $chkAlgo, $fileChk);
    }

    /**
     * Create a new file on the TUS server.
     */
    private function createFile(int $fileLen, string $mime): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->tusUploader);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Tus-Resumable: 1.0.0',
            'Content-Type: application/offset+octet-stream',
            'Upload-Length: '.$fileLen,
            sprintf(
                'Upload-Metadata: relativePath bnVsbA==,name %s,type %s,filetype %s,filename %s',
                base64_encode($mime),
                base64_encode('image/webp'),
                base64_encode('image/webp'),
                base64_encode('image.webp')
            ),
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            Log::warning('TUS create file curl error', [
                'errno' => $errno,
                'error' => curl_error($ch),
            ]);

            return null;
        }

        if ($httpCode !== 201) {
            Log::warning('TUS create file failed', [
                'status' => $httpCode,
                'result' => $result,
            ]);

            return null;
        }

        // Extract Location header using same approach as legacy code
        $headers = $this->getHeaders($result);
        $location = $headers['location'] ?? null;

        if (! $location) {
            Log::warning('TUS create file no location header', [
                'result' => $result,
                'headers' => $headers,
            ]);

            return null;
        }

        return $location;
    }

    /**
     * Verify file exists on the TUS server (HEAD request).
     */
    private function verifyFileExists(string $url): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Tus-Resumable: 1.0.0',
            'Content-Type: application/offset+octet-stream',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            Log::warning('TUS verify file curl error', [
                'url' => $url,
                'errno' => $errno,
            ]);

            return false;
        }

        if ($httpCode === 404) {
            Log::warning('TUS file not found', ['url' => $url]);

            return false;
        }

        return $httpCode === 200;
    }

    /**
     * Upload file data to the TUS server (PATCH request).
     * Always uploads from offset 0 for fresh uploads.
     */
    private function uploadData(string $url, string $data, string $chkAlgo, string $fileChk): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/offset+octet-stream',
            'Tus-Resumable: 1.0.0',
            'Upload-Offset: 0',
            'Upload-Checksum: '.$chkAlgo.' '.$fileChk,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            Log::error('TUS upload data curl error', [
                'url' => $url,
                'errno' => $errno,
            ]);

            return null;
        }

        if ($httpCode === 200 || $httpCode === 204) {
            return $url;
        }

        Log::warning('TUS upload data failed', [
            'url' => $url,
            'status' => $httpCode,
            'result' => substr($result, 0, 500),
        ]);

        return null;
    }

    /**
     * Parse HTTP headers from curl response.
     * Matches the legacy Tus::getHeaders() implementation.
     */
    private function getHeaders(string $respHeaders): array
    {
        $headers = [];

        $headerText = substr($respHeaders, 0, strpos($respHeaders, "\r\n\r\n"));
        if (! $headerText) {
            $headerText = $respHeaders;
        }

        foreach (explode("\r\n", $headerText) as $i => $line) {
            if ($i === 0) {
                $headers['http_code'] = $line;
            } else {
                $parts = explode(': ', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower($parts[0])] = $parts[1];
                }
            }
        }

        return $headers;
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
