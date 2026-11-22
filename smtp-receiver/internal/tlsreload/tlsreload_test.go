package tlsreload

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func writeCert(t *testing.T, dir, cn string) {
	t.Helper()
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: cn},
		NotBefore: time.Now(), NotAfter: time.Now().Add(time.Hour), DNSNames: []string{cn}}
	der, _ := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	kb, _ := x509.MarshalECPrivateKey(key)
	os.WriteFile(filepath.Join(dir, "c.crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o600)
	os.WriteFile(filepath.Join(dir, "c.key"), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}), 0o600)
}

func TestMissingThenAppears(t *testing.T) {
	dir := t.TempDir()
	l, err := New(filepath.Join(dir, "c.crt"), filepath.Join(dir, "c.key"))
	if err != nil {
		t.Fatalf("missing cert should not be fatal: %v", err)
	}
	if _, err := l.GetCertificate(nil); err != ErrNoCertificate {
		t.Fatalf("expected ErrNoCertificate, got %v", err)
	}
	writeCert(t, dir, "mx.example.com")
	c, err := l.GetCertificate(nil)
	if err != nil || c == nil {
		t.Fatalf("expected cert after it appears: %v", err)
	}
}

func TestReloadOnChange(t *testing.T) {
	dir := t.TempDir()
	writeCert(t, dir, "one.example.com")
	l, err := New(filepath.Join(dir, "c.crt"), filepath.Join(dir, "c.key"))
	if err != nil {
		t.Fatal(err)
	}
	first, _ := l.GetCertificate(nil)

	writeCert(t, dir, "two.example.com")
	future := time.Now().Add(2 * time.Hour)
	os.Chtimes(filepath.Join(dir, "c.crt"), future, future)
	l.now = func() time.Time { return time.Now().Add(2 * time.Minute) }
	second, _ := l.GetCertificate(nil)
	if first == second {
		t.Fatal("expected reloaded certificate")
	}
}

func TestInvalidExistingCertIsFatal(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, "c.crt"), []byte("junk"), 0o600)
	os.WriteFile(filepath.Join(dir, "c.key"), []byte("junk"), 0o600)
	if _, err := New(filepath.Join(dir, "c.crt"), filepath.Join(dir, "c.key")); err == nil {
		t.Fatal("expected error for invalid cert")
	}
}
