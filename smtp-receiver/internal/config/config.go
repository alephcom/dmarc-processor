// Package config loads smtp-receiver settings from environment variables.
package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	ListenAddr      string // SMTP listen address, e.g. ":25"
	OpsAddr         string // health/metrics listen address, e.g. ":9090"
	Hostname        string // name announced in the SMTP greeting
	ReportsDomain   string // only recipients @ this domain are accepted
	AppURL          string // base URL of the Laravel app (internal network)
	HMACSecret      string // shared secret with the app
	SpoolDir        string // durable spool directory
	TLSCertFile     string // optional PEM cert for STARTTLS
	TLSKeyFile      string // optional PEM key for STARTTLS
	MaxMessageBytes int64
	MaxRecipients   int
	RateConnPerMin  int           // per remote IP
	RateMsgPerHour  int           // per remote IP
	RetryFor        time.Duration // give up and dead-letter after this long
	ValidatorTTL    time.Duration // recipient validation cache TTL
}

func Load() (*Config, error) {
	c := &Config{
		ListenAddr:      env("SMTP_LISTEN", ":25"),
		OpsAddr:         env("OPS_LISTEN", ":9090"),
		Hostname:        env("SMTP_HOSTNAME", "localhost"),
		ReportsDomain:   strings.ToLower(env("REPORTS_DOMAIN", "")),
		AppURL:          strings.TrimRight(env("APP_INTERNAL_URL", "http://app"), "/"),
		HMACSecret:      env("INGEST_HMAC_SECRET", ""),
		SpoolDir:        env("SPOOL_DIR", "/spool"),
		TLSCertFile:     env("TLS_CERT_FILE", ""),
		TLSKeyFile:      env("TLS_KEY_FILE", ""),
		MaxMessageBytes: int64(envInt("MAX_MESSAGE_BYTES", 10*1024*1024)),
		MaxRecipients:   envInt("MAX_RECIPIENTS", 5),
		RateConnPerMin:  envInt("RATE_CONN_PER_MIN", 30),
		RateMsgPerHour:  envInt("RATE_MSG_PER_HOUR", 500),
		RetryFor:        envDuration("RETRY_FOR", 72*time.Hour),
		ValidatorTTL:    envDuration("VALIDATOR_TTL", 60*time.Second),
	}
	if c.ReportsDomain == "" {
		return nil, fmt.Errorf("REPORTS_DOMAIN is required")
	}
	if len(c.HMACSecret) < 32 {
		return nil, fmt.Errorf("INGEST_HMAC_SECRET must be at least 32 characters")
	}
	if (c.TLSCertFile == "") != (c.TLSKeyFile == "") {
		return nil, fmt.Errorf("TLS_CERT_FILE and TLS_KEY_FILE must be set together")
	}
	return c, nil
}

func env(k, def string) string {
	if v, ok := os.LookupEnv(k); ok && v != "" {
		return v
	}
	return def
}

func envInt(k string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(k)); err == nil {
		return v
	}
	return def
}

func envDuration(k string, def time.Duration) time.Duration {
	if v, err := time.ParseDuration(os.Getenv(k)); err == nil {
		return v
	}
	return def
}
