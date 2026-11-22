// Package signer implements the HMAC request signing shared with the Laravel app.
//
// Signature = hex(HMAC-SHA256(secret, timestamp + "\n" + METHOD + "\n" + path + "\n" + hex(sha256(body))))
// sent in the X-Signature header, with the unix timestamp in X-Timestamp.
package signer

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"strconv"
	"time"
)

const (
	HeaderTimestamp = "X-Timestamp"
	HeaderSignature = "X-Signature"
)

func Sign(secret []byte, ts int64, method, path string, body []byte) string {
	bodyHash := sha256.Sum256(body)
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte(strconv.FormatInt(ts, 10) + "\n" + method + "\n" + path + "\n" + hex.EncodeToString(bodyHash[:])))
	return hex.EncodeToString(mac.Sum(nil))
}

// SignRequest adds timestamp and signature headers. body must be the exact request body.
func SignRequest(req *http.Request, secret []byte, body []byte, now time.Time) {
	ts := now.Unix()
	req.Header.Set(HeaderTimestamp, strconv.FormatInt(ts, 10))
	req.Header.Set(HeaderSignature, Sign(secret, ts, req.Method, req.URL.Path, body))
}
