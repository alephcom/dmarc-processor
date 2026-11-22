package forwarder

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/signer"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/spool"
)

var secret = []byte("0123456789abcdef0123456789abcdef")

func newSpoolWith(t *testing.T, now time.Time) (*spool.Spool, spool.Meta) {
	t.Helper()
	sp, err := spool.New(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	m, err := sp.Store(spool.Meta{ID: spool.NewID(now), ReceivedAt: now, Recipients: []string{"rua-aaaaaaaaaa@r.example"}}, strings.NewReader("Subject: hi\r\n\r\nbody"))
	if err != nil {
		t.Fatal(err)
	}
	return sp, m
}

func TestDeliverSuccessRemovesEntry(t *testing.T) {
	now := time.Now()
	sp, m := newSpoolWith(t, now)
	var got Payload
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		ts := r.Header.Get(signer.HeaderTimestamp)
		var tsInt int64
		json.Unmarshal([]byte(ts), &tsInt)
		if signer.Sign(secret, tsInt, r.Method, r.URL.Path, body) != r.Header.Get(signer.HeaderSignature) {
			w.WriteHeader(401)
			return
		}
		json.Unmarshal(body, &got)
		w.WriteHeader(202)
	}))
	defer srv.Close()

	f := &Forwarder{Spool: sp, AppURL: srv.URL, Secret: secret, RetryFor: time.Hour, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
	f.ProcessOnce(context.Background())

	if got.ID != m.ID {
		t.Fatalf("payload id %q want %q", got.ID, m.ID)
	}
	raw, _ := base64.StdEncoding.DecodeString(got.MessageB64)
	if string(raw) != "Subject: hi\r\n\r\nbody" {
		t.Fatalf("body mismatch: %q", raw)
	}
	if p, _ := sp.Pending(); len(p) != 0 {
		t.Fatalf("expected spool empty, got %d", len(p))
	}
	if f.Delivered.Load() != 1 {
		t.Fatal("delivered counter")
	}
}

func TestDeliverRetryThenDead(t *testing.T) {
	now := time.Now()
	sp, _ := newSpoolWith(t, now)
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(503)
	}))
	defer srv.Close()

	clock := now
	f := &Forwarder{Spool: sp, AppURL: srv.URL, Secret: secret, RetryFor: time.Hour,
		Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Now: func() time.Time { return clock }}

	f.ProcessOnce(context.Background())
	p, _ := sp.Pending()
	if len(p) != 1 || p[0].Attempts != 1 || !p[0].NextAttempt.After(clock) {
		t.Fatalf("expected one retry-scheduled entry, got %+v", p)
	}
	// Not due yet: no new call.
	f.ProcessOnce(context.Background())
	if calls.Load() != 1 {
		t.Fatalf("expected 1 call, got %d", calls.Load())
	}
	// Past the retry window: dead-lettered.
	clock = now.Add(2 * time.Hour)
	f.ProcessOnce(context.Background())
	if p, _ := sp.Pending(); len(p) != 0 {
		t.Fatal("expected entry moved out of incoming")
	}
	if sp.DeadCount() != 1 {
		t.Fatal("expected one dead letter")
	}
}

func TestPermanentRejectionDeadLetters(t *testing.T) {
	sp, _ := newSpoolWith(t, time.Now())
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(422) }))
	defer srv.Close()
	f := &Forwarder{Spool: sp, AppURL: srv.URL, Secret: secret, RetryFor: time.Hour, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
	f.ProcessOnce(context.Background())
	if sp.DeadCount() != 1 {
		t.Fatal("expected 422 to dead-letter immediately")
	}
}

func TestBackoff(t *testing.T) {
	if Backoff(1) != 30*time.Second || Backoff(2) != time.Minute || Backoff(20) != time.Hour {
		t.Fatal("unexpected backoff values")
	}
}
