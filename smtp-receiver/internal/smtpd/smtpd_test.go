package smtpd

import (
	"context"
	"io"
	"log/slog"
	"net"
	"net/smtp"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	gosmtp "github.com/emersion/go-smtp"

	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/ratelimit"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/spool"
)

type fakeChecker map[string]bool

func (f fakeChecker) Valid(_ context.Context, token string) (bool, error) { return f[token], nil }

func startServer(t *testing.T, msgLimit int) (addr string, sp *spool.Spool, dir string) {
	t.Helper()
	dir = t.TempDir()
	sp, err := spool.New(dir)
	if err != nil {
		t.Fatal(err)
	}
	be := &Backend{
		ReportsDomain: "reports.example.com",
		Checker:       fakeChecker{"aaaaaaaaaa": true},
		Spool:         sp,
		MsgLimiter:    ratelimit.New(msgLimit, time.Hour),
		Log:           slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
	srv := gosmtp.NewServer(be)
	srv.Domain = "mx.example.com"
	srv.MaxMessageBytes = 1024
	srv.MaxRecipients = 5
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	go srv.Serve(l)
	t.Cleanup(func() { srv.Close() })
	return l.Addr().String(), sp, dir
}

func TestAcceptsValidRecipient(t *testing.T) {
	addr, sp, _ := startServer(t, 0)
	msg := "From: noreply-dmarc@google.com\r\nSubject: Report\r\n\r\nhello\r\n"
	err := smtp.SendMail(addr, nil, "noreply-dmarc@google.com", []string{"rua-aaaaaaaaaa@reports.example.com"}, []byte(msg))
	if err != nil {
		t.Fatalf("send: %v", err)
	}
	p, _ := sp.Pending()
	if len(p) != 1 {
		t.Fatalf("expected 1 spooled message, got %d", len(p))
	}
	if p[0].MailFrom != "noreply-dmarc@google.com" || p[0].RemoteIP != "127.0.0.1" {
		t.Fatalf("unexpected meta %+v", p[0])
	}
	body, _ := sp.Body(p[0].ID)
	if !strings.Contains(string(body), "Subject: Report") {
		t.Fatalf("body not stored: %q", body)
	}
}

func TestRejectsUnknownRecipient(t *testing.T) {
	addr, sp, _ := startServer(t, 0)
	for _, rcpt := range []string{"rua-bbbbbbbbbb@reports.example.com", "someone@reports.example.com", "rua-aaaaaaaaaa@evil.example.com"} {
		err := smtp.SendMail(addr, nil, "x@y.z", []string{rcpt}, []byte("Subject: x\r\n\r\nx\r\n"))
		if err == nil || !strings.Contains(err.Error(), "550") {
			t.Fatalf("%s: expected 550, got %v", rcpt, err)
		}
	}
	if p, _ := sp.Pending(); len(p) != 0 {
		t.Fatal("nothing should be spooled")
	}
}

func TestRejectsOversizeAndLeavesNoPartialFiles(t *testing.T) {
	addr, sp, dir := startServer(t, 0)
	big := "Subject: big\r\n\r\n" + strings.Repeat(strings.Repeat("x", 70)+"\r\n", 60)
	err := smtp.SendMail(addr, nil, "x@y.z", []string{"rua-aaaaaaaaaa@reports.example.com"}, []byte(big))
	if err == nil || !strings.Contains(err.Error(), "552") {
		t.Fatalf("expected 552, got %v", err)
	}
	if p, _ := sp.Pending(); len(p) != 0 {
		t.Fatal("oversize message should not be spooled")
	}
	files, _ := os.ReadDir(filepath.Join(dir, "incoming"))
	if len(files) != 0 {
		t.Fatalf("expected no leftover files, got %d", len(files))
	}
}

func TestRejectsOverlongLine(t *testing.T) {
	addr, sp, _ := startServer(t, 0)
	msg := "Subject: x\r\n\r\n" + strings.Repeat("y", 3000) + "\r\n"
	err := smtp.SendMail(addr, nil, "x@y.z", []string{"rua-aaaaaaaaaa@reports.example.com"}, []byte(msg))
	if err == nil || strings.HasPrefix(strings.TrimSpace(err.Error()), "4") {
		t.Fatalf("expected permanent failure, got %v", err)
	}
	if p, _ := sp.Pending(); len(p) != 0 {
		t.Fatal("nothing should be spooled")
	}
}

func TestMessageRateLimit(t *testing.T) {
	addr, _, _ := startServer(t, 1)
	send := func() error {
		return smtp.SendMail(addr, nil, "x@y.z", []string{"rua-aaaaaaaaaa@reports.example.com"}, []byte("Subject: x\r\n\r\nx\r\n"))
	}
	if err := send(); err != nil {
		t.Fatalf("first send: %v", err)
	}
	if err := send(); err == nil || !strings.Contains(err.Error(), "450") {
		t.Fatalf("expected 450, got %v", err)
	}
}
