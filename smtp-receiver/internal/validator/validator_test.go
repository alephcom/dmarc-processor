package validator

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func TestParseRecipient(t *testing.T) {
	cases := []struct {
		in    string
		token string
		ok    bool
	}{
		{"rua-abcdefgh23@reports.example.com", "abcdefgh23", true},
		{"<RUA-ABCDEFGH23@Reports.Example.com>", "abcdefgh23", true},
		{"rua-abcdefgh23@other.example.com", "", false},
		{"rua-abcdefgh1@reports.example.com", "", false},   // 1 is not base32, and too short
		{"rua-abcdefgh234@reports.example.com", "", false}, // too long
		{"postmaster@reports.example.com", "", false},
		{"rua-abcdefgh23", "", false},
	}
	for _, c := range cases {
		tok, ok := ParseRecipient(c.in, "reports.example.com")
		if tok != c.token || ok != c.ok {
			t.Errorf("%q: got (%q,%v) want (%q,%v)", c.in, tok, ok, c.token, c.ok)
		}
	}
}

func TestHTTPCheckerCachesAndSigns(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		if r.Header.Get("X-Signature") == "" || r.Header.Get("X-Timestamp") == "" {
			w.WriteHeader(401)
			return
		}
		if r.URL.Path == "/internal/recipients/aaaaaaaaaa" {
			w.WriteHeader(200)
			return
		}
		w.WriteHeader(404)
	}))
	defer srv.Close()

	c := NewHTTPChecker(srv.URL, []byte("secret"), time.Minute)
	for i := 0; i < 3; i++ {
		ok, err := c.Valid(context.Background(), "aaaaaaaaaa")
		if err != nil || !ok {
			t.Fatalf("expected valid, got %v %v", ok, err)
		}
	}
	ok, err := c.Valid(context.Background(), "bbbbbbbbbb")
	if err != nil || ok {
		t.Fatalf("expected invalid, got %v %v", ok, err)
	}
	if calls.Load() != 2 {
		t.Fatalf("expected 2 upstream calls (cached), got %d", calls.Load())
	}
}

func TestHTTPCheckerServerErrorIsError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(500) }))
	defer srv.Close()
	c := NewHTTPChecker(srv.URL, []byte("secret"), time.Minute)
	if _, err := c.Valid(context.Background(), "aaaaaaaaaa"); err == nil {
		t.Fatal("expected error on 500")
	}
}
