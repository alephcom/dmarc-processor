// Package ratelimit provides a simple fixed-window limiter keyed by remote IP.
package ratelimit

import (
	"sync"
	"time"
)

type window struct {
	start time.Time
	count int
}

type Limiter struct {
	limit  int
	period time.Duration
	now    func() time.Time

	mu sync.Mutex
	m  map[string]*window
}

func New(limit int, period time.Duration) *Limiter {
	return &Limiter{limit: limit, period: period, now: time.Now, m: map[string]*window{}}
}

// Allow records one event for key and reports whether it is within the limit.
// A limit <= 0 disables limiting.
func (l *Limiter) Allow(key string) bool {
	if l.limit <= 0 {
		return true
	}
	now := l.now()
	l.mu.Lock()
	defer l.mu.Unlock()
	w, ok := l.m[key]
	if !ok || now.Sub(w.start) >= l.period {
		if len(l.m) > 50000 {
			l.gc(now)
		}
		l.m[key] = &window{start: now, count: 1}
		return true
	}
	w.count++
	return w.count <= l.limit
}

func (l *Limiter) gc(now time.Time) {
	for k, w := range l.m {
		if now.Sub(w.start) >= l.period {
			delete(l.m, k)
		}
	}
}
