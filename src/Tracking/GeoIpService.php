<?php

declare(strict_types=1);

namespace MailAI\Tracking;

use GeoIp2\Database\Reader;
use Exception as GeoIpException;

/**
 * GeoIpService
 *
 * Resolves an IP address to a country code using MaxMind's GeoLite2/
 * GeoIP2 database via the `geoip2/geoip2` composer package. Requires a
 * local .mmdb file (MaxMind's license terms don't allow redistributing
 * it, so it's NOT committed to this repo — see storage/geoip/README).
 * Degrades gracefully to "unknown" if the database file isn't present
 * rather than fataling — tracking should never break email delivery.
 */
final class GeoIpService
{
    private ?Reader $reader = null;

    public function __construct(private readonly string $mmdbPath)
    {
        if (is_file($this->mmdbPath)) {
            try {
                $this->reader = new Reader($this->mmdbPath);
            } catch (GeoIpException) {
                $this->reader = null;
            }
        }
    }

    public function countryCode(string $ip): ?string
    {
        if ($this->reader === null) {
            return null;
        }

        try {
            return $this->reader->country($ip)->country->isoCode;
        } catch (GeoIpException) {
            return null;
        }
    }
}
