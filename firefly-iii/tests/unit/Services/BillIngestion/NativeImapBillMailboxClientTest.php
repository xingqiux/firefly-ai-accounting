<?php

declare(strict_types=1);

namespace Tests\unit\Services\BillIngestion;

use FireflyIII\Services\BillIngestion\NativeImapBillMailboxClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 *
 * @covers \FireflyIII\Services\BillIngestion\NativeImapBillMailboxClient
 */
final class NativeImapBillMailboxClientTest extends TestCase
{
    public function testReadBytesKeepsBinaryMailboxLiteralsIntact(): void
    {
        $client = new NativeImapBillMailboxClient();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "line one\r\nzip\0bytes\r\nline three");
        rewind($stream);

        $reflection = new ReflectionClass($client);
        $property   = $reflection->getProperty('stream');
        $property->setValue($client, $stream);

        $method = $reflection->getMethod('readBytes');
        $message = "line one\r\nzip\0bytes\r\nline three";
        $result  = $method->invoke($client, strlen($message));

        $this->assertSame($message, $result);
    }
}
