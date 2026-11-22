<?php

namespace App\Console\Commands;

use App\Intel\MmdbAsnDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads the offline IP-to-ASN database: IPinfo Lite when IPINFO_TOKEN is set,
 * otherwise DB-IP's free "IP to ASN Lite" (CC BY 4.0, attribution shown in the UI).
 */
class UpdateIpDatabase extends Command
{
    protected $signature = 'dmarc:update-ip-db {--if-missing : Only download when no database is installed}';

    protected $description = 'Download or refresh the offline IP-to-ASN database';

    public function handle(): int
    {
        $path = config('dmarc.asn_db_path');
        if ($this->option('if-missing') && is_file($path)) {
            return self::SUCCESS;
        }
        @mkdir(dirname($path), 0775, true);
        $tmp = $path.'.download';

        foreach ($this->sources() as $name => $url) {
            $this->line("Downloading {$name}…");
            try {
                $resp = Http::timeout(300)->withOptions(['sink' => $tmp])->get($url);
            } catch (Throwable $e) {
                $this->warn("  failed: {$e->getMessage()}");

                continue;
            }
            if (! $resp->successful()) {
                $this->warn("  failed: HTTP {$resp->status()}");

                continue;
            }
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '.gz') && ! $this->gunzipInPlace($tmp)) {
                $this->warn('  failed: could not decompress');

                continue;
            }
            // Sanity check before replacing the live file: Google's resolver must map to AS15169.
            $probe = (new MmdbAsnDatabase($tmp))->lookup('8.8.8.8');
            if ($probe?->asn !== 15169) {
                $this->warn('  failed: downloaded file is not a valid ASN database');

                continue;
            }
            rename($tmp, $path);
            $this->info("Installed {$name} at {$path}");

            return self::SUCCESS;
        }

        @unlink($tmp);
        $this->error('Could not download an IP database. Sender identification will use reverse DNS and SPF only.');

        return self::FAILURE;
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        if ($token = config('dmarc.ipinfo_token')) {
            return ['IPinfo Lite' => 'https://ipinfo.io/data/ipinfo_lite.mmdb?token='.urlencode($token)];
        }
        $out = [];
        // DB-IP publishes monthly; early in the month this month's file may not exist yet.
        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $out['DB-IP ASN Lite '.$month->format('Y-m')] = 'https://download.db-ip.com/free/dbip-asn-lite-'.$month->format('Y-m').'.mmdb.gz';
        }

        return $out;
    }

    private function gunzipInPlace(string $file): bool
    {
        $in = @gzopen($file, 'rb');
        $out = @fopen($file.'.raw', 'wb');
        if (! $in || ! $out) {
            return false;
        }
        while (! gzeof($in)) {
            fwrite($out, gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);

        return rename($file.'.raw', $file);
    }
}
