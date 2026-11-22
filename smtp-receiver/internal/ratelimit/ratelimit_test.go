package ratelimit

import (
	"testing"
	"time"
)

func TestLimiter(t *testing.T) {
	now := time.Unix(0, 0)
	l := New(2, time.Minute)
	l.now = func() time.Time { return now }
	if !l.Allow("a") || !l.Allow("a") {
		t.Fatal("first two should pass")
	}
	if l.Allow("a") {
		t.Fatal("third should be blocked")
	}
	if !l.Allow("b") {
		t.Fatal("other key unaffected")
	}
	now = now.Add(time.Minute)
	if !l.Allow("a") {
		t.Fatal("new window should pass")
	}
}

func TestDisabled(t *testing.T) {
	l := New(0, time.Minute)
	for i := 0; i < 100; i++ {
		if !l.Allow("x") {
			t.Fatal("disabled limiter blocked")
		}
	}
}
