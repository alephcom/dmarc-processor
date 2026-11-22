// Command smtp-receiver is a receive-only SMTP server that accepts DMARC aggregate
// reports for registered reporting addresses and hands them to the Laravel app.
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/emersion/go-smtp"

	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/config"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/forwarder"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/metrics"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/ratelimit"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/smtpd"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/spool"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/tlsreload"
	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/validator"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	cfg, err := config.Load()
	if err != nil {
		log.Error("config", "err", err)
		os.Exit(2)
	}

	sp, err := spool.New(cfg.SpoolDir)
	if err != nil {
		log.Error("spool", "err", err)
		os.Exit(1)
	}
	sp.CleanupTemp()

	reg := metrics.New()
	secret := []byte(cfg.HMACSecret)

	fwd := &forwarder.Forwarder{
		Spool: sp, AppURL: cfg.AppURL, Secret: secret, RetryFor: cfg.RetryFor, Log: log,
		Delivered: reg.Counter("smtp_receiver_delivered_total", "Messages delivered to the app"),
		Failed:    reg.Counter("smtp_receiver_delivery_failures_total", "Failed delivery attempts"),
		DeadCount: reg.Counter("smtp_receiver_dead_lettered_total", "Messages moved to dead-letter"),
	}
	reg.Gauge("smtp_receiver_dead_letters", "Messages currently in dead-letter", func() int64 { return int64(sp.DeadCount()) })
	reg.Gauge("smtp_receiver_spool_pending", "Messages waiting for delivery", func() int64 {
		p, _ := sp.Pending()
		return int64(len(p))
	})

	be := &smtpd.Backend{
		ReportsDomain: cfg.ReportsDomain,
		Checker:       validator.NewHTTPChecker(cfg.AppURL, secret, cfg.ValidatorTTL),
		Spool:         sp,
		ConnLimiter:   ratelimit.New(cfg.RateConnPerMin, time.Minute),
		MsgLimiter:    ratelimit.New(cfg.RateMsgPerHour, time.Hour),
		OnStored:      fwd.Kick,
		Log:           log,
		Counters: smtpd.Counters{
			Connections:  reg.Counter("smtp_receiver_connections_total", "SMTP connections"),
			Accepted:     reg.Counter("smtp_receiver_accepted_total", "Messages accepted"),
			RejectedRcpt: reg.Counter("smtp_receiver_rejected_recipients_total", "Recipients rejected"),
			RateLimited:  reg.Counter("smtp_receiver_rate_limited_total", "Rate-limited events"),
			TempFail:     reg.Counter("smtp_receiver_temp_failures_total", "Temporary failures returned"),
		},
	}

	srv := smtp.NewServer(be)
	srv.Addr = cfg.ListenAddr
	srv.Domain = cfg.Hostname
	srv.MaxMessageBytes = cfg.MaxMessageBytes
	srv.MaxRecipients = cfg.MaxRecipients
	srv.ReadTimeout = 5 * time.Minute
	srv.WriteTimeout = time.Minute
	if cfg.TLSCertFile != "" {
		l, err := tlsreload.New(cfg.TLSCertFile, cfg.TLSKeyFile)
		if err != nil {
			log.Error("tls", "err", err)
			os.Exit(1)
		}
		srv.TLSConfig = l.Config()
		if !l.Ready() {
			log.Warn("TLS certificate not found yet; STARTTLS will work once it is issued", "file", cfg.TLSCertFile)
		}
	} else {
		log.Warn("no TLS certificate configured; STARTTLS disabled")
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	go fwd.Run(ctx)

	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) { w.Write([]byte("ok\n")) })
	mux.Handle("/metrics", reg)
	ops := &http.Server{Addr: cfg.OpsAddr, Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	go func() {
		if err := ops.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Error("ops server", "err", err)
		}
	}()

	go func() {
		log.Info("listening", "addr", cfg.ListenAddr, "domain", cfg.ReportsDomain)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, smtp.ErrServerClosed) {
			log.Error("smtp server", "err", err)
			stop()
		}
	}()

	<-ctx.Done()
	log.Info("shutting down")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	_ = srv.Shutdown(shutdownCtx)
	_ = ops.Shutdown(shutdownCtx)
}
