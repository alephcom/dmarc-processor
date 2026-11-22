package signer

import "testing"

// Shared test vector: the PHP side (web/tests/Unit/IngestSignatureTest.php) asserts the same value.
func TestSignKnownVector(t *testing.T) {
	got := Sign([]byte("0123456789abcdef0123456789abcdef"), 1700000000, "POST", "/internal/ingest", []byte("hello"))
	want := "3d07881db413406740913b6bfd184a0c938da5bde794526d3ea33702636b7fab"
	if got != want {
		t.Fatalf("got %s want %s", got, want)
	}
}
