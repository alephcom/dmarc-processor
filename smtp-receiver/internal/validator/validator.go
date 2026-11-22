// Package validator decides whether a recipient address is a live reporting address.
package validator

import (
	"context"
	"fmt"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/alephcom/dmarc-processor/smtp-receiver/internal/signer"
)

// Tokens are 10 chars of lowercase RFC 4648 base32 (a-z, 2-7).
var localPartRe = regexp.MustCompile(`^rua-([a-z2-7]{10})$`)

// ParseRecipient returns the token for an address like rua-abcdefghij@reports.example.com.
func ParseRecipient(addr, reportsDomain string) (string, bool) {
	addr = strings.ToLower(strings.TrimSpace(addr))
	addr = strings.TrimSuffix(strings.TrimPrefix(addr, "<"), ">")
	at := strings.LastIndexByte(addr, '@')
	if at <= 0 || addr[at+1:] != reportsDomain {
		return "", false
	}
	m := localPartRe.FindStringSubmatch(addr[:at])
	if m == nil {
		return "", false
	}
	return m[1], true
}

type Checker interface {
	Valid(ctx context.Context, token string) (bool, error)
}

type entry struct {
	valid   bool
	expires time.Time
}

// HTTPChecker asks the app GET /internal/recipients/{token}: 200 = valid, 404 = unknown.
type HTTPChecker struct {
	BaseURL string
	Secret  []byte
	TTL     time.Duration
	Client  *http.Client

	mu    sync.Mutex
	cache map[string]entry
}

func NewHTTPChecker(baseURL string, secret []byte, ttl time.Duration) *HTTPChecker {
	return &HTTPChecker{
		BaseURL: baseURL, Secret: secret, TTL: ttl,
		Client: &http.Client{Timeout: 5 * time.Second},
		cache:  map[string]entry{},
	}
}

func (c *HTTPChecker) Valid(ctx context.Context, token string) (bool, error) {
	now := time.Now()
	c.mu.Lock()
	if e, ok := c.cache[token]; ok && now.Before(e.expires) {
		c.mu.Unlock()
		return e.valid, nil
	}
	c.mu.Unlock()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.BaseURL+"/internal/recipients/"+token, nil)
	if err != nil {
		return false, err
	}
	req.Header.Set("Accept", "application/json")
	signer.SignRequest(req, c.Secret, nil, now)
	resp, err := c.Client.Do(req)
	if err != nil {
		return false, err
	}
	resp.Body.Close()

	var valid bool
	switch resp.StatusCode {
	case http.StatusOK:
		valid = true
	case http.StatusNotFound:
		valid = false
	default:
		return false, fmt.Errorf("recipient check: unexpected status %d", resp.StatusCode)
	}

	c.mu.Lock()
	if len(c.cache) > 10000 {
		c.cache = map[string]entry{}
	}
	c.cache[token] = entry{valid: valid, expires: now.Add(c.TTL)}
	c.mu.Unlock()
	return valid, nil
}
