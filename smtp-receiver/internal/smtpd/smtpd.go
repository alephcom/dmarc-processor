// Package smtpd implements the receive-only SMTP backend.
package smtpd

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"net"
	"sync/atomic"
	"time"

	gosmtp "github.com/emersion/go-smtp"

	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/ratelimit"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/spool"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/validator"
)

type Counters struct {
	Connections  *atomic.Int64
	Accepted     *atomic.Int64
	RejectedRcpt *atomic.Int64
	RateLimited  *atomic.Int64
	TempFail     *atomic.Int64
}

type Backend struct {
	ReportsDomain string
	Checker       validator.Checker
	Spool         *spool.Spool
	ConnLimiter   *ratelimit.Limiter
	MsgLimiter    *ratelimit.Limiter
	OnStored      func() // e.g. forwarder.Kick
	Log           *slog.Logger
	Counters      Counters
	Now           func() time.Time
}

var (
	errRateLimited = &gosmtp.SMTPError{Code: 421, EnhancedCode: gosmtp.EnhancedCode{4, 7, 0}, Message: "Too many connections, try again later"}
	errMsgRate     = &gosmtp.SMTPError{Code: 450, EnhancedCode: gosmtp.EnhancedCode{4, 7, 1}, Message: "Rate limit exceeded, try again later"}
	errNoUser      = &gosmtp.SMTPError{Code: 550, EnhancedCode: gosmtp.EnhancedCode{5, 1, 1}, Message: "No such recipient"}
	errTempLookup  = &gosmtp.SMTPError{Code: 451, EnhancedCode: gosmtp.EnhancedCode{4, 3, 0}, Message: "Temporary lookup failure, try again later"}
	errTempStore   = &gosmtp.SMTPError{Code: 451, EnhancedCode: gosmtp.EnhancedCode{4, 3, 0}, Message: "Temporary local error, try again later"}
	errBadData     = &gosmtp.SMTPError{Code: 554, EnhancedCode: gosmtp.EnhancedCode{5, 6, 0}, Message: "Malformed message data"}
	errNoRcpt      = &gosmtp.SMTPError{Code: 554, EnhancedCode: gosmtp.EnhancedCode{5, 5, 1}, Message: "No valid recipients"}
)

func (b *Backend) now() time.Time {
	if b.Now != nil {
		return b.Now()
	}
	return time.Now()
}

func (b *Backend) inc(c *atomic.Int64) {
	if c != nil {
		c.Add(1)
	}
}

func (b *Backend) NewSession(c *gosmtp.Conn) (gosmtp.Session, error) {
	ip := remoteIP(c.Conn().RemoteAddr())
	b.inc(b.Counters.Connections)
	if b.ConnLimiter != nil && !b.ConnLimiter.Allow(ip) {
		b.inc(b.Counters.RateLimited)
		b.Log.Warn("connection rate limited", "ip", ip)
		return nil, errRateLimited
	}
	return &Session{b: b, conn: c, ip: ip}, nil
}

type Session struct {
	b     *Backend
	conn  *gosmtp.Conn
	ip    string
	from  string
	rcpts []string
}

func (s *Session) Reset() {
	s.from = ""
	s.rcpts = nil
}

func (s *Session) Logout() error { return nil }

func (s *Session) Mail(from string, _ *gosmtp.MailOptions) error {
	if s.b.MsgLimiter != nil && !s.b.MsgLimiter.Allow(s.ip) {
		s.b.inc(s.b.Counters.RateLimited)
		s.b.Log.Warn("message rate limited", "ip", s.ip)
		return errMsgRate
	}
	s.from = from
	return nil
}

func (s *Session) Rcpt(to string, _ *gosmtp.RcptOptions) error {
	token, ok := validator.ParseRecipient(to, s.b.ReportsDomain)
	if !ok {
		s.b.inc(s.b.Counters.RejectedRcpt)
		return errNoUser
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	valid, err := s.b.Checker.Valid(ctx, token)
	if err != nil {
		s.b.inc(s.b.Counters.TempFail)
		s.b.Log.Error("recipient check failed", "err", err)
		return errTempLookup
	}
	if !valid {
		s.b.inc(s.b.Counters.RejectedRcpt)
		return errNoUser
	}
	s.rcpts = append(s.rcpts, to)
	return nil
}

func (s *Session) Data(r io.Reader) error {
	if len(s.rcpts) == 0 {
		_, _ = io.Copy(io.Discard, r)
		return errNoRcpt
	}
	_, isTLS := s.conn.TLSConnectionState()
	now := s.b.now()
	meta := spool.Meta{
		ID:         spool.NewID(now),
		ReceivedAt: now.UTC(),
		RemoteIP:   s.ip,
		Helo:       s.conn.Hostname(),
		MailFrom:   s.from,
		Recipients: append([]string(nil), s.rcpts...),
		TLS:        isTLS,
	}
	rr := &recordingReader{r: r}
	meta, err := s.b.Spool.Store(meta, rr)
	if err != nil {
		if rr.err != nil {
			// The failure came from the client's data stream, not from local storage.
			_, _ = io.Copy(io.Discard, r)
			var se *gosmtp.SMTPError
			if errors.As(rr.err, &se) {
				return se // e.g. ErrDataTooLarge -> 552
			}
			return errBadData
		}
		s.b.inc(s.b.Counters.TempFail)
		s.b.Log.Error("spool store failed", "err", err)
		return errTempStore
	}
	s.b.inc(s.b.Counters.Accepted)
	s.b.Log.Info("accepted", "id", meta.ID, "ip", s.ip, "from", s.from, "rcpts", len(s.rcpts), "size", meta.Size, "tls", isTLS)
	if s.b.OnStored != nil {
		s.b.OnStored()
	}
	return nil
}

func remoteIP(a net.Addr) string {
	if a == nil {
		return ""
	}
	host, _, err := net.SplitHostPort(a.String())
	if err != nil {
		return a.String()
	}
	return host
}

// recordingReader remembers the first non-EOF read error so callers can tell
// client-side data errors apart from local write errors.
type recordingReader struct {
	r   io.Reader
	err error
}

func (rr *recordingReader) Read(p []byte) (int, error) {
	n, err := rr.r.Read(p)
	if err != nil && err != io.EOF && rr.err == nil {
		rr.err = err
	}
	return n, err
}
