#!/usr/bin/env python3
"""Smoke-test the smtp-receiver over SMTP (port 25 by default).

Checks, in order:
  1. Connects and reads the banner
  2. EHLO, lists the advertised extensions (AUTH must not be offered)
  3. STARTTLS (unless --no-tls), then EHLO again
  4. If the reports domain is known: an unknown rua- address is rejected (550)
  5. If --to is given: sends a small gzipped sample DMARC aggregate report

Only uses the Python standard library.

Examples:
  scripts/smtp-test.py mx.reports.example.com --reports-domain reports.example.com
  scripts/smtp-test.py mx.reports.example.com --to rua-abcdefgh23@reports.example.com --domain customer.com
  scripts/smtp-test.py 127.0.0.1 --insecure --to rua-abcdefgh23@reports.example.com --domain customer.com
"""

import argparse
import gzip
import smtplib
import socket
import ssl
import sys
import time
import uuid
from email.message import EmailMessage
from email.utils import formatdate, make_msgid

failures = 0


def ok(msg):
    print(f"  [PASS] {msg}")


def fail(msg):
    global failures
    failures += 1
    print(f"  [FAIL] {msg}")


def info(msg):
    print(f"  [INFO] {msg}")


def sample_report(domain, reporter_ip):
    now = int(time.time())
    report_id = f"smtp-test-{uuid.uuid4()}"
    xml = f"""<?xml version="1.0" encoding="UTF-8"?>
<feedback>
  <report_metadata>
    <org_name>smtp-test.invalid</org_name>
    <email>noreply@smtp-test.invalid</email>
    <report_id>{report_id}</report_id>
    <date_range><begin>{now - 86400}</begin><end>{now}</end></date_range>
  </report_metadata>
  <policy_published>
    <domain>{domain}</domain>
    <adkim>r</adkim><aspf>r</aspf><p>none</p><sp>none</sp><pct>100</pct>
  </policy_published>
  <record>
    <row>
      <source_ip>{reporter_ip}</source_ip>
      <count>1</count>
      <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
    </row>
    <identifiers><header_from>{domain}</header_from></identifiers>
    <auth_results>
      <dkim><domain>{domain}</domain><result>pass</result></dkim>
      <spf><domain>{domain}</domain><result>pass</result></spf>
    </auth_results>
  </record>
</feedback>
"""
    filename = f"smtp-test.invalid!{domain}!{now - 86400}!{now}.xml.gz"
    return report_id, filename, gzip.compress(xml.encode())


def main():
    p = argparse.ArgumentParser(description="Smoke-test the DMARC smtp-receiver.")
    p.add_argument("host", nargs="?", default="localhost", help="server to connect to (default: localhost)")
    p.add_argument("--port", type=int, default=25)
    p.add_argument("--to", help="a live rua-<token>@<reports-domain> address; sends a sample report to it")
    p.add_argument("--domain", help="policy domain for the sample report (must be the token's domain or a subdomain)")
    p.add_argument("--reports-domain", help="reports domain, for the unknown-recipient check (taken from --to if omitted)")
    p.add_argument("--from", dest="mail_from", default="smtp-test@smtp-test.invalid", help="envelope sender")
    p.add_argument("--helo", default=socket.getfqdn(), help="EHLO name")
    p.add_argument("--no-tls", action="store_true", help="don't use STARTTLS")
    p.add_argument("--insecure", action="store_true", help="don't verify the TLS certificate (e.g. connecting by IP)")
    p.add_argument("--timeout", type=float, default=15)
    p.add_argument("-v", "--verbose", action="store_true", help="print the raw SMTP conversation")
    a = p.parse_args()

    if a.to and not a.domain:
        p.error("--domain is required with --to")
    reports_domain = a.reports_domain or (a.to.rsplit("@", 1)[1] if a.to and "@" in a.to else None)

    print(f"Connecting to {a.host}:{a.port} ...")
    try:
        s = smtplib.SMTP(timeout=a.timeout, local_hostname=a.helo)
        if a.verbose:
            s.set_debuglevel(1)
        code, banner = s.connect(a.host, a.port)
    except (OSError, smtplib.SMTPException) as e:
        fail(f"connect: {e}")
        return 1
    if code == 220:
        ok(f"banner: {banner.decode(errors='replace')}")
    else:
        fail(f"banner: {code} {banner.decode(errors='replace')}")
        return 1

    try:
        s.ehlo()
        exts = ", ".join(f"{k.upper()} {v}".strip() for k, v in s.esmtp_features.items())
        ok(f"EHLO: {exts or '(no extensions)'}")
        if s.has_extn("auth"):
            fail("AUTH is advertised (it should not be)")
        if "size" in s.esmtp_features:
            info(f"max message size: {int(s.esmtp_features['size']) / 1048576:.1f} MiB")

        if a.no_tls:
            info("STARTTLS skipped (--no-tls)")
        elif not s.has_extn("starttls"):
            fail("STARTTLS not advertised")
        else:
            ctx = ssl.create_default_context()
            if a.insecure:
                ctx.check_hostname = False
                ctx.verify_mode = ssl.CERT_NONE
            try:
                s.starttls(context=ctx)
                s.ehlo()
                tls = s.sock
                cert = tls.getpeercert() if not a.insecure else None
                subject = dict(x[0] for x in cert["subject"]).get("commonName") if cert else "unverified"
                expires = cert.get("notAfter") if cert else "n/a"
                ok(f"STARTTLS: {tls.version()} {tls.cipher()[0]}, cert CN={subject}, expires {expires}")
            except ssl.SSLError as e:
                fail(f"STARTTLS: {e} (use --insecure if connecting by IP or with a test cert)")
                return 1

        if reports_domain:
            bogus = f"rua-aaaaaaaaaa@{reports_domain}"
            s.mail(a.mail_from)
            code, resp = s.rcpt(bogus)
            if code == 550:
                ok(f"unknown recipient {bogus} rejected: {code} {resp.decode(errors='replace')}")
            else:
                fail(f"unknown recipient {bogus} got {code} {resp.decode(errors='replace')} (expected 550)")
            s.rset()
        else:
            info("unknown-recipient check skipped (pass --reports-domain or --to)")

        if a.to:
            report_id, filename, data = sample_report(a.domain, "192.0.2.1")
            msg = EmailMessage()
            msg["From"] = a.mail_from
            msg["To"] = a.to
            msg["Subject"] = f"Report Domain: {a.domain} Submitter: smtp-test.invalid Report-ID: {report_id}"
            msg["Date"] = formatdate(localtime=True)
            msg["Message-ID"] = make_msgid(domain="smtp-test.invalid")
            msg.set_content("Test DMARC aggregate report sent by scripts/smtp-test.py.")
            msg.add_attachment(data, maintype="application", subtype="gzip", filename=filename)
            try:
                s.send_message(msg, from_addr=a.mail_from, to_addrs=[a.to])
                ok(f"sample report accepted (report_id {report_id})")
                info("check the admin Ingest queue / the domain's reports in the web UI")
            except smtplib.SMTPRecipientsRefused as e:
                code, resp = e.recipients[a.to]
                fail(f"recipient refused: {code} {resp.decode(errors='replace')} (is the token live?)")
            except smtplib.SMTPException as e:
                fail(f"send: {e}")
        s.quit()
    except (OSError, smtplib.SMTPException) as e:
        fail(f"SMTP error: {e}")

    print("FAILED" if failures else "OK")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
