<?php

namespace App\Dmarc;

/**
 * Pulls aggregate-report XML out of a raw RFC 5322 message.
 *
 * Handles the shapes reporters actually send:
 *  - multipart/* with a .zip / .gz / .xml attachment (Google, Microsoft, Yahoo, ...)
 *  - a single-part message whose body is the attachment
 * with base64, quoted-printable or 7/8bit transfer encodings.
 */
class ReportExtractor
{
    public const MAX_DECOMPRESSED_BYTES = 50 * 1024 * 1024;

    public const MAX_RATIO = 200; // decompressed / compressed

    public const MAX_DEPTH = 5;

    /** @return list<array{filename: ?string, xml: string}> */
    public function extract(string $raw): array
    {
        $out = [];
        $this->walk($raw, 0, $out);

        return $out;
    }

    /** @param list<array{filename: ?string, xml: string}> $out */
    private function walk(string $entity, int $depth, array &$out): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        [$headers, $body] = $this->splitEntity($entity);
        [$type, $params] = $this->parseHeaderValue($headers['content-type'] ?? 'text/plain');
        $type = strtolower($type);

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $params['boundary'] ?? null;
            if ($boundary === null || $boundary === '') {
                return;
            }
            foreach ($this->splitMultipart($body, $boundary) as $part) {
                $this->walk($part, $depth + 1, $out);
            }

            return;
        }
        if ($type === 'message/rfc822') {
            $this->walk($body, $depth + 1, $out);

            return;
        }

        $filename = $this->filename($headers, $params);
        $content = $this->decodeTransfer($body, strtolower(trim($headers['content-transfer-encoding'] ?? '7bit')));
        $kind = $this->detectKind($type, $filename, $content);
        if ($kind === null) {
            return;
        }

        foreach ($this->decodeArchive($kind, $content) as $xml) {
            $out[] = ['filename' => $filename, 'xml' => $xml];
        }
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function splitEntity(string $entity): array
    {
        $entity = ltrim($entity, "\r\n");
        $pos = strpos($entity, "\r\n\r\n");
        $sepLen = 4;
        $lfPos = strpos($entity, "\n\n");
        if ($pos === false || ($lfPos !== false && $lfPos < $pos)) {
            $pos = $lfPos;
            $sepLen = 2;
        }
        if ($pos === false) {
            return [$this->parseHeaders($entity), ''];
        }

        return [$this->parseHeaders(substr($entity, 0, $pos)), substr($entity, $pos + $sepLen)];
    }

    /** @return array<string, string> lower-cased names; first occurrence wins */
    private function parseHeaders(string $block): array
    {
        $block = preg_replace("/\r?\n[ \t]+/", ' ', $block); // unfold
        $headers = [];
        foreach (preg_split("/\r?\n/", $block) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if (! isset($headers[$name])) {
                $headers[$name] = trim(substr($line, $colon + 1));
            }
        }

        return $headers;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function parseHeaderValue(string $value): array
    {
        $parts = str_getcsv($value, ';', '"', '');
        $main = trim(array_shift($parts) ?? '');
        $params = [];
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) {
                continue;
            }
            $k = strtolower(trim(substr($p, 0, $eq)));
            $v = trim(substr($p, $eq + 1));
            $v = trim($v, '"');
            if (str_ends_with($k, '*')) { // RFC 2231: charset'lang'value
                $k = rtrim($k, '*');
                $v = rawurldecode(preg_replace("/^[^']*'[^']*'/", '', $v));
            }
            $params[$k] = $v;
        }

        return [$main, $params];
    }

    /** @return list<string> */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--'.$boundary;
        $parts = [];
        $current = null; // null = still in the preamble
        foreach (preg_split("/\r?\n/", $body) as $line) {
            $trimmed = rtrim($line, " \t");
            if ($trimmed === $delim.'--') {
                break; // closing delimiter; ignore the epilogue
            }
            if ($trimmed === $delim) {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }
                $current = [];

                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null && $current !== []) {
            $parts[] = implode("\r\n", $current);
        }

        return $parts;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $ctParams
     */
    private function filename(array $headers, array $ctParams): ?string
    {
        if (isset($headers['content-disposition'])) {
            [, $dp] = $this->parseHeaderValue($headers['content-disposition']);
            if (! empty($dp['filename'])) {
                return $this->decodeMimeWord($dp['filename']);
            }
        }
        if (! empty($ctParams['name'])) {
            return $this->decodeMimeWord($ctParams['name']);
        }

        return null;
    }

    private function decodeMimeWord(string $s): string
    {
        if (str_contains($s, '=?')) {
            $d = iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false) {
                return $d;
            }
        }

        return $s;
    }

    private function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** @return 'zip'|'gzip'|'xml'|null */
    private function detectKind(string $type, ?string $filename, string $content): ?string
    {
        // Magic bytes are the most reliable signal; reporters mislabel content types constantly.
        if (str_starts_with($content, "PK\x03\x04")) {
            return 'zip';
        }
        if (str_starts_with($content, "\x1f\x8b")) {
            return 'gzip';
        }
        $head = ltrim(substr($content, 0, 512), "\xEF\xBB\xBF \t\r\n");
        $looksXml = str_starts_with($head, '<?xml') || str_starts_with($head, '<feedback');
        $fn = strtolower($filename ?? '');
        if ($looksXml && (str_ends_with($fn, '.xml') || str_contains($type, 'xml') || str_contains($head, '<feedback'))) {
            return 'xml';
        }

        return null;
    }

    /** @return list<string> */
    private function decodeArchive(string $kind, string $content): array
    {
        return match ($kind) {
            'xml' => [$content],
            'gzip' => [$this->gunzip($content)],
            'zip' => $this->unzip($content),
        };
    }

    private function gunzip(string $data): string
    {
        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
        if ($ctx === false) {
            throw new ReportParseException('Could not initialise gzip decoder');
        }
        $limit = $this->limitFor(strlen($data));
        $out = '';
        foreach (str_split($data, 65536) as $chunk) {
            $piece = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                throw new ReportParseException('Corrupt gzip data');
            }
            $out .= $piece;
            if (strlen($out) > $limit) {
                throw new ReportParseException('Decompressed gzip exceeds size limit');
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function unzip(string $data): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmarczip');
        file_put_contents($tmp, $data);
        $zip = new \ZipArchive;
        try {
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                throw new ReportParseException('Corrupt zip archive');
            }
            $limit = $this->limitFor(strlen($data));
            $xmls = [];
            for ($i = 0; $i < $zip->numFiles && count($xmls) < 1; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if (str_ends_with($name, '/') || ! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }
                if (($stat['size'] ?? 0) > $limit) {
                    throw new ReportParseException('Zip entry exceeds size limit');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new ReportParseException('Unreadable zip entry');
                }
                $content = '';
                while (! feof($stream)) {
                    $content .= fread($stream, 65536);
                    if (strlen($content) > $limit) { // don't trust the declared size
                        fclose($stream);
                        throw new ReportParseException('Zip entry exceeds size limit');
                    }
                }
                fclose($stream);
                $xmls[] = $content;
            }

            return $xmls;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    private function limitFor(int $compressed): int
    {
        return (int) min(self::MAX_DECOMPRESSED_BYTES, max(1024 * 1024, $compressed * self::MAX_RATIO));
    }
}
