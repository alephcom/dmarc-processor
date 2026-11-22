// Package tlsreload serves a certificate from disk and picks up renewals automatically.
//
// The certificate may not exist yet when the receiver starts (Caddy issues it shortly after
// first boot). Until it appears, STARTTLS handshakes fail and senders retry or fall back to
// plaintext; once the file exists it is loaded without a restart.
package tlsreload

import (
	"crypto/tls"
	"errors"
	"os"
	"sync"
	"time"
)

var ErrNoCertificate = errors.New("tls certificate not available yet")

type Loader struct {
	certFile, keyFile string
	now               func() time.Time

	mu      sync.Mutex
	cert    *tls.Certificate
	modTime time.Time
	checked time.Time
}

// New returns a loader. It fails only if the files exist but cannot be loaded.
func New(certFile, keyFile string) (*Loader, error) {
	l := &Loader{certFile: certFile, keyFile: keyFile, now: time.Now}
	if err := l.load(); err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	return l, nil
}

// Ready reports whether a certificate is currently loaded.
func (l *Loader) Ready() bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.cert != nil
}

func (l *Loader) load() error {
	st, err := os.Stat(l.certFile)
	if err != nil {
		return err
	}
	c, err := tls.LoadX509KeyPair(l.certFile, l.keyFile)
	if err != nil {
		return err
	}
	l.cert, l.modTime = &c, st.ModTime()
	return nil
}

// GetCertificate re-checks the files at most once a minute (every call while none is loaded).
func (l *Loader) GetCertificate(*tls.ClientHelloInfo) (*tls.Certificate, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	now := l.now()
	if l.cert == nil || now.Sub(l.checked) > time.Minute {
		l.checked = now
		if st, err := os.Stat(l.certFile); err == nil && (l.cert == nil || !st.ModTime().Equal(l.modTime)) {
			_ = l.load() // on failure keep serving the previous cert, if any
		}
	}
	if l.cert == nil {
		return nil, ErrNoCertificate
	}
	return l.cert, nil
}

func (l *Loader) Config() *tls.Config {
	return &tls.Config{GetCertificate: l.GetCertificate, MinVersion: tls.VersionTLS12}
}
