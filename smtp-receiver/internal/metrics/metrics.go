// Package metrics exposes a handful of counters in Prometheus text format without extra dependencies.
package metrics

import (
	"fmt"
	"net/http"
	"sort"
	"sync"
	"sync/atomic"
)

type Registry struct {
	mu       sync.Mutex
	counters map[string]*atomic.Int64
	help     map[string]string
	gauges   map[string]func() int64
}

func New() *Registry {
	return &Registry{counters: map[string]*atomic.Int64{}, help: map[string]string{}, gauges: map[string]func() int64{}}
}

func (r *Registry) Counter(name, help string) *atomic.Int64 {
	r.mu.Lock()
	defer r.mu.Unlock()
	if c, ok := r.counters[name]; ok {
		return c
	}
	c := &atomic.Int64{}
	r.counters[name] = c
	r.help[name] = help
	return c
}

func (r *Registry) Gauge(name, help string, fn func() int64) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.gauges[name] = fn
	r.help[name] = help
}

func (r *Registry) ServeHTTP(w http.ResponseWriter, _ *http.Request) {
	r.mu.Lock()
	defer r.mu.Unlock()
	w.Header().Set("Content-Type", "text/plain; version=0.0.4")
	names := make([]string, 0, len(r.counters))
	for n := range r.counters {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		fmt.Fprintf(w, "# HELP %s %s\n# TYPE %s counter\n%s %d\n", n, r.help[n], n, n, r.counters[n].Load())
	}
	gnames := make([]string, 0, len(r.gauges))
	for n := range r.gauges {
		gnames = append(gnames, n)
	}
	sort.Strings(gnames)
	for _, n := range gnames {
		fmt.Fprintf(w, "# HELP %s %s\n# TYPE %s gauge\n%s %d\n", n, r.help[n], n, n, r.gauges[n]())
	}
}
