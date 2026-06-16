<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteBillFileDownloader
{
    public function download(string $url): RemoteBillFile
    {
        $response = Http::timeout(30)->get($url);
        if (false === $response->successful()) {
            throw new RuntimeException(sprintf('账单文件下载失败，HTTP 状态 %d。', $response->status()));
        }

        $content = $response->body();
        if ('' === $content) {
            throw new RuntimeException('账单文件下载失败，返回文件为空。');
        }

        return new RemoteBillFile(
            content: $content,
            filename: $this->filenameFromDisposition((string) $response->header('Content-Disposition')) ?? 'wechat-pay-statement.zip',
            contentType: (string) $response->header('Content-Type'),
        );
    }

    private function filenameFromDisposition(string $disposition): ?string
    {
        if (1 === preg_match('/filename\*=[^;\']*\'[^\']*\'([^;]+)/i', $disposition, $matches)) {
            $filename = rawurldecode(trim($matches[1], " \t\n\r\0\x0B\""));

            return '' === $filename ? null : $filename;
        }
        if (1 === preg_match('/filename="?([^";]+)"?/i', $disposition, $matches)) {
            $filename = trim($matches[1]);

            return '' === $filename ? null : $filename;
        }

        return null;
    }
}
