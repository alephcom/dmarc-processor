// Package forwarder delivers spooled messages to the app's ingest endpoint, with retries.
package forwarder

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"sync/atomic"
	"time"

	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/signer"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/spool"
)

// Payload is the JSON body POSTed to /internal/ingest. The whole body is HMAC-signed.
type Payload struct {
	ID         string    `json:"id"`
	ReceivedAt time.Time `json:"received_at"`
	RemoteIP   string    `json:"remote_ip"`
	Helo       string    `json:"helo"`
	MailFrom   string    `json:"mail_from"`
	Recipients []string  `json:"recipients"`
	TLS        bool      `json:"tls"`
	MessageB64 string    `json:"message_b64"`
}

type Forwarder struct {
	Spool    *spool.Spool
	AppURL   string
	Secret   []byte
	RetryFor time.Duration
	Client   *http.Client
	Log      *slog.Logger
	Now      func() time.Time

	Delivered *atomic.Int64
	Failed    *atomic.Int64
	DeadCount *atomic.Int64

	kick chan struct{}
}

func (f *Forwarder) init() {
	if f.Client == nil {
		f.Client = &http.Client{Timeout: 60 * time.Second}
	}
	if f.Now == nil {
		f.Now = time.Now
	}
	if f.kick == nil {
		f.kick = make(chan struct{}, 1)
	}
	for _, c := range []**atomic.Int64{&f.Delivered, &f.Failed, &f.DeadCount} {
		if *c == nil {
			*c = &atomic.Int64{}
		}
	}
}

// Kick asks the run loop to process the spool now (called after a new message is stored).
func (f *Forwarder) Kick() {
	f.init()
	select {
	case f.kick <- struct{}{}:
	default:
	}
}

// Run processes the spool until ctx is cancelled.
func (f *Forwarder) Run(ctx context.Context) {
	f.init()
	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()
	for {
		f.ProcessOnce(ctx)
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		case <-f.kick:
		}
	}
}

// ProcessOnce attempts delivery of every pending entry that is due.
func (f *Forwarder) ProcessOnce(ctx context.Context) {
	f.init()
	pending, err := f.Spool.Pending()
	if err != nil {
		f.Log.Error("list spool", "err", err)
		return
	}
	for _, m := range pending {
		if ctx.Err() != nil {
			return
		}
		now := f.Now()
		if now.Before(m.NextAttempt) {
			continue
		}
		err, permanent := f.deliver(ctx, m)
		if err == nil {
			if rerr := f.Spool.Remove(m.ID); rerr != nil {
				f.Log.Error("remove delivered", "id", m.ID, "err", rerr)
			}
			f.Delivered.Add(1)
			f.Log.Info("delivered", "id", m.ID, "size", m.Size)
			continue
		}
		f.Failed.Add(1)
		m.Attempts++
		m.LastError = err.Error()
		if permanent || now.Sub(m.ReceivedAt) > f.RetryFor {
			f.Log.Warn("dead-lettering", "id", m.ID, "attempts", m.Attempts, "err", err, "permanent", permanent)
			if derr := f.Spool.Dead(m); derr != nil {
				f.Log.Error("dead-letter", "id", m.ID, "err", derr)
			}
			f.DeadCount.Add(1)
			continue
		}
		m.NextAttempt = now.Add(Backoff(m.Attempts))
		f.Log.Warn("delivery failed, will retry", "id", m.ID, "attempts", m.Attempts, "next", m.NextAttempt, "err", err)
		if uerr := f.Spool.Update(m); uerr != nil {
			f.Log.Error("update spool meta", "id", m.ID, "err", uerr)
		}
	}
}

// Backoff returns 30s, 1m, 2m, 4m ... capped at 1h.
func Backoff(attempts int) time.Duration {
	d := 30 * time.Second
	for i := 1; i < attempts && d < time.Hour; i++ {
		d *= 2
	}
	if d > time.Hour {
		d = time.Hour
	}
	return d
}

func (f *Forwarder) deliver(ctx context.Context, m spool.Meta) (err error, permanent bool) {
	body, err := f.Spool.Body(m.ID)
	if err != nil {
		return fmt.Errorf("read body: %w", err), true
	}
	payload, err := json.Marshal(Payload{
		ID: m.ID, ReceivedAt: m.ReceivedAt, RemoteIP: m.RemoteIP, Helo: m.Helo,
		MailFrom: m.MailFrom, Recipients: m.Recipients, TLS: m.TLS,
		MessageB64: base64.StdEncoding.EncodeToString(body),
	})
	if err != nil {
		return err, true
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, f.AppURL+"/internal/ingest", bytes.NewReader(payload))
	if err != nil {
		return err, true
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	signer.SignRequest(req, f.Secret, payload, f.Now())

	resp, err := f.Client.Do(req)
	if err != nil {
		return err, false
	}
	defer resp.Body.Close()
	snippet, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		return nil, false
	case resp.StatusCode == http.StatusRequestTimeout, resp.StatusCode == http.StatusTooManyRequests,
		resp.StatusCode == http.StatusUnauthorized: // 401: likely clock skew or secret rotation; keep retrying
		return fmt.Errorf("ingest: status %d: %s", resp.StatusCode, snippet), false
	case resp.StatusCode >= 400 && resp.StatusCode < 500:
		return fmt.Errorf("ingest rejected: status %d: %s", resp.StatusCode, snippet), true
	default:
		return fmt.Errorf("ingest: status %d: %s", resp.StatusCode, snippet), false
	}
}
