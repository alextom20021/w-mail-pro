# GeoIP database

Place a MaxMind `GeoLite2-Country.mmdb` (free, requires a MaxMind account
license key: https://www.maxmind.com/en/geolite2/signup) or the paid
`GeoIP2-Country.mmdb` here as `GeoLite2-Country.mmdb`.

Not committed to git — MaxMind's license does not permit redistribution.
`GeoIpService` degrades gracefully (returns `null` country codes) if this
file is missing, so its absence never breaks email sending or tracking,
only geo-enrichment of analytics.
