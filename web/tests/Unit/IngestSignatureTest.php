<?php

namespace Tests\Unit;

use App\Support\IngestSignature;
use PHPUnit\Framework\TestCase;

class IngestSignatureTest extends TestCase
{
    /** Same vector as smtp-receiver/internal/signer/signer_test.go. */
    public function test_matches_go_receiver_vector(): void
    {
        $this->assertSame(
            '3d07881db413406740913b6bfd184a0c938da5bde794526d3ea33702636b7fab',
            IngestSignature::sign('0123456789abcdef0123456789abcdef', 1700000000, 'POST', '/internal/ingest', 'hello'),
        );
    }
}
