// Package spool stores accepted messages durably on disk until the app has them.
//
// Layout:
//
//	<dir>/incoming/<id>.eml   raw message
//	<dir>/incoming/<id>.json  metadata; written last, so its presence marks a complete entry
//	<dir>/dead/               entries that could not be delivered within the retry window
package spool

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

type Meta struct {
	ID          string    `json:"id"`
	ReceivedAt  time.Time `json:"received_at"`
	RemoteIP    string    `json:"remote_ip"`
	Helo        string    `json:"helo"`
	MailFrom    string    `json:"mail_from"`
	Recipients  []string  `json:"recipients"` // full addresses as given in RCPT TO
	TLS         bool      `json:"tls"`
	Size        int64     `json:"size"`
	Attempts    int       `json:"attempts"`
	NextAttempt time.Time `json:"next_attempt"`
	LastError   string    `json:"last_error,omitempty"`
}

type Spool struct {
	incoming string
	dead     string
}

func New(dir string) (*Spool, error) {
	s := &Spool{incoming: filepath.Join(dir, "incoming"), dead: filepath.Join(dir, "dead")}
	for _, d := range []string{s.incoming, s.dead} {
		if err := os.MkdirAll(d, 0o750); err != nil {
			return nil, err
		}
	}
	return s, nil
}

func NewID(now time.Time) string {
	b := make([]byte, 6)
	_, _ = rand.Read(b)
	return fmt.Sprintf("%d-%s", now.UTC().UnixNano(), hex.EncodeToString(b))
}

// Store writes the message body from r and its metadata, fsyncing both.
// It returns only once the message is durable.
func (s *Spool) Store(meta Meta, r io.Reader) (Meta, error) {
	emlPath := filepath.Join(s.incoming, meta.ID+".eml")
	n, err := writeFileSync(emlPath, r)
	if err != nil {
		os.Remove(emlPath)
		return meta, err
	}
	meta.Size = n
	if err := s.writeMeta(meta); err != nil {
		os.Remove(emlPath)
		return meta, err
	}
	return meta, nil
}

func (s *Spool) writeMeta(meta Meta) error {
	b, err := json.Marshal(meta)
	if err != nil {
		return err
	}
	final := filepath.Join(s.incoming, meta.ID+".json")
	tmp := final + ".tmp"
	if _, err := writeFileSync(tmp, strings.NewReader(string(b))); err != nil {
		os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, final)
}

// Update persists changed metadata (attempt counters etc.).
func (s *Spool) Update(meta Meta) error { return s.writeMeta(meta) }

// Pending lists complete entries, oldest first.
func (s *Spool) Pending() ([]Meta, error) {
	files, err := filepath.Glob(filepath.Join(s.incoming, "*.json"))
	if err != nil {
		return nil, err
	}
	sort.Strings(files)
	out := make([]Meta, 0, len(files))
	for _, f := range files {
		b, err := os.ReadFile(f)
		if err != nil {
			continue
		}
		var m Meta
		if json.Unmarshal(b, &m) == nil && m.ID != "" {
			out = append(out, m)
		}
	}
	return out, nil
}

func (s *Spool) Body(id string) ([]byte, error) {
	return os.ReadFile(filepath.Join(s.incoming, id+".eml"))
}

// Remove deletes a delivered entry. Metadata goes first so a crash never leaves a half entry visible.
func (s *Spool) Remove(id string) error {
	if err := os.Remove(filepath.Join(s.incoming, id+".json")); err != nil && !os.IsNotExist(err) {
		return err
	}
	return os.Remove(filepath.Join(s.incoming, id+".eml"))
}

// Dead moves an entry to the dead-letter directory.
func (s *Spool) Dead(meta Meta) error {
	if err := os.Rename(filepath.Join(s.incoming, meta.ID+".eml"), filepath.Join(s.dead, meta.ID+".eml")); err != nil {
		return err
	}
	b, _ := json.Marshal(meta)
	if err := os.WriteFile(filepath.Join(s.dead, meta.ID+".json"), b, 0o640); err != nil {
		return err
	}
	return os.Remove(filepath.Join(s.incoming, meta.ID+".json"))
}

func (s *Spool) DeadCount() int {
	files, _ := filepath.Glob(filepath.Join(s.dead, "*.json"))
	return len(files)
}

// CleanupTemp removes orphaned partial files left by a crash (no matching .json).
func (s *Spool) CleanupTemp() {
	tmps, _ := filepath.Glob(filepath.Join(s.incoming, "*.tmp"))
	for _, f := range tmps {
		os.Remove(f)
	}
	emls, _ := filepath.Glob(filepath.Join(s.incoming, "*.eml"))
	for _, f := range emls {
		if _, err := os.Stat(strings.TrimSuffix(f, ".eml") + ".json"); os.IsNotExist(err) {
			if st, err := os.Stat(f); err == nil && time.Since(st.ModTime()) > time.Hour {
				os.Remove(f)
			}
		}
	}
}

func writeFileSync(path string, r io.Reader) (int64, error) {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o640)
	if err != nil {
		return 0, err
	}
	n, err := io.Copy(f, r)
	if err == nil {
		err = f.Sync()
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	return n, err
}
