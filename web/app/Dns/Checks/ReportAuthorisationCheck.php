<?php

namespace App\Dns\Checks;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;

/**
 * RFC 7489 §7.1: because reports go to a different domain than the customer's, the reports
 * domain must publish <customer-domain>._report._dmarc.<reports-domain> TXT "v=DMARC1".
 * The MSP normally covers every customer at once with a wildcard record.
 */
class ReportAuthorisationCheck
{
    public function __construct(private readonly Resolver $dns) {}

    public function check(string $domain, string $reportsDomain): CheckResult
    {
        $name = "{$domain}._report._dmarc.{$reportsDomain}";
        try {
            $records = $this->dns->txt($name);
        } catch (DnsLookupException $e) {
            return CheckResult::lookupFailed('rua_auth', $e->getMessage());
        }
        foreach ($records as $r) {
            if (DmarcRecord::looksLikeDmarc($r)) {
                return new CheckResult('rua_auth', CheckResult::OK, $r, ['name' => $name]);
            }
        }

        return new CheckResult('rua_auth', CheckResult::ERROR, null, ['name' => $name], [
            Issue::error('not_authorised', 'Our reporting domain is not yet authorised to receive reports for this domain, so some providers (including Google) will not send them.', "This is a one-off setting on our side, not yours: your MSP needs to publish TXT \"v=DMARC1\" at *._report._dmarc.{$reportsDomain}."),
        ]);
    }
}
