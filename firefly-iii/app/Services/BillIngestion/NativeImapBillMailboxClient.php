<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use RuntimeException;

class NativeImapBillMailboxClient implements ImapBillMailboxClient
{
    /** @var null|resource */
    private $stream = null;

    private int $tag = 1;

    public function close(): void
    {
        if (is_resource($this->stream)) {
            try {
                $this->writeCommand('LOGOUT');
            } catch (\Throwable) {
                // Closing the local stream is still useful when the remote side is already gone.
            }
            fclose($this->stream);
        }

        $this->stream = null;
    }

    public function connect(BillMailboxConfig $config): void
    {
        $scheme       = 'ssl' === $config->encryption ? 'ssl' : 'tcp';
        $remoteSocket = sprintf('%s://%s:%d', $scheme, $config->host, $config->port);
        $stream       = @stream_socket_client($remoteSocket, $errno, $errstr, 30);
        if (false === $stream) {
            throw new RuntimeException(sprintf('Could not connect to IMAP mailbox: %s', '' === $errstr ? (string) $errno : $errstr));
        }

        stream_set_timeout($stream, 30);
        $this->stream = $stream;
        $this->readUntilTagged('');
        if ('starttls' === $config->encryption) {
            $this->writeCommand('STARTTLS');
            if (false === stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable STARTTLS for IMAP mailbox.');
            }
        }

        $this->writeCommand(sprintf('LOGIN %s %s', $this->quote($config->username), $this->quote($config->password)));
    }

    public function fetchRawMessage(string $uid): string
    {
        $raw = $this->fetchLiteral(sprintf('UID FETCH %s BODY.PEEK[]', $uid));

        if ('' === $raw) {
            throw new RuntimeException(sprintf('IMAP message %s did not return a raw body.', $uid));
        }

        return $raw;
    }

    public function markSeen(string $uid): void
    {
        $this->writeCommand(sprintf('UID STORE %s +FLAGS.SILENT (\\Seen)', $uid));
    }

    public function search(string $criteria, int $limit): array
    {
        $lines = $this->writeCommand(sprintf('UID SEARCH %s', $criteria));
        $uids  = [];

        foreach ($lines as $line) {
            if (1 === preg_match('/^\* SEARCH\s*(.*)$/', $line, $matches)) {
                $found = preg_split('/\s+/', trim($matches[1])) ?: [];
                foreach ($found as $uid) {
                    if ('' !== $uid) {
                        $uids[] = $uid;
                    }
                }
            }
        }

        return array_slice($uids, 0, max(1, $limit));
    }

    public function selectFolder(string $folder): void
    {
        $this->writeCommand(sprintf('SELECT %s', $this->quoteMailbox($folder)));
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function quoteMailbox(string $folder): string
    {
        if (preg_match('/^[A-Za-z0-9_\-\/\[\] ]+$/', $folder)) {
            return $this->quote($folder);
        }

        return $this->quote('INBOX');
    }

    private function fetchLiteral(string $command): string
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('IMAP client is not connected.');
        }

        $tag = sprintf('A%04d', $this->tag++);
        fwrite($this->stream, sprintf("%s %s\r\n", $tag, $command));

        $raw = '';
        while (false !== ($line = fgets($this->stream))) {
            if (1 === preg_match('/\{(\d+)\}\r?\n?$/', $line, $matches)) {
                $raw = $this->readBytes((int) $matches[1]);

                continue;
            }

            if (str_starts_with($line, $tag.' ')) {
                if (str_contains($line, ' OK')) {
                    return $raw;
                }

                throw new RuntimeException(sprintf('IMAP command failed: %s', trim($line)));
            }
        }

        throw new RuntimeException('IMAP connection closed unexpectedly.');
    }

    /**
     * @return array<int, string>
     */
    private function readUntilTagged(string $tag): array
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('IMAP client is not connected.');
        }

        $lines = [];
        while (false !== ($line = fgets($this->stream))) {
            $lines[] = $line;
            if ('' !== $tag && str_starts_with($line, $tag.' ')) {
                if (str_contains($line, ' OK')) {
                    return $lines;
                }

                throw new RuntimeException(sprintf('IMAP command failed: %s', trim($line)));
            }
            if ('' === $tag) {
                return $lines;
            }
        }

        throw new RuntimeException('IMAP connection closed unexpectedly.');
    }

    private function readBytes(int $length): string
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('IMAP client is not connected.');
        }

        $buffer = '';
        while (strlen($buffer) < $length && false !== ($chunk = fread($this->stream, $length - strlen($buffer)))) {
            $buffer .= $chunk;
        }

        if (strlen($buffer) !== $length) {
            throw new RuntimeException('IMAP literal ended before all bytes were read.');
        }

        return $buffer;
    }

    /**
     * @return array<int, string>
     */
    private function writeCommand(string $command): array
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('IMAP client is not connected.');
        }

        $tag = sprintf('A%04d', $this->tag++);
        fwrite($this->stream, sprintf("%s %s\r\n", $tag, $command));

        return $this->readUntilTagged($tag);
    }
}
