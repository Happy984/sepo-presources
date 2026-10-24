---
Title: Sepo Resources 
Contributors: Euneolink Solutions
Requires PHP: 8.1
License: GPLv2 or later
Description: A document library for PDFs, Word files, spreadsheets and slides, with search, groups, pagination, likes, download counts, sign-in-only downloads and PDF preview.
---

* Library page at /resources/ (address changeable in Resources → Settings), plus group and keyword pages.
* Each document has a file (uploaded or linked), author block, details, citation line, PDF preview and related documents.
* Every download goes through /resources/download/{id}/, which applies the download rules and counts it once per visitor per window.
* Uploaded documents are stored in uploads/sepo-resources/, blocked from direct access on Apache/LiteSpeed. For nginx, add the rule shown on the settings page.
* Listing anywhere: [sepo_resources group="slug" tag="slug" ids="1,2" per_page="10" limit="-1" search="no" groups="no"]
* REST API under /wp-json/sepo-resources/v1: GET /stats, POST /downloaded, POST /like, POST /reset.
* Filter: sepo_resources_document ($doc array, WP_Post) adjusts what the views receive.
* Activity log: every like, unlike and download is recorded with the country, browser and version, operating system, device type (computer, phone, tablet, TV, bot) and the user agent. See Resources → Activity (filters, breakdowns, CSV export) and the "Who liked and downloaded it" box on each document.
* Filter: sepo_resources_country_lookup (null, $ip) — return a two-letter code to supply countries yourself, for example from a local MaxMind database. Action: sepo_resources_activity ($id, $kind, $visitor) after each logged event.

## Notes 

* Country: taken first from the header your CDN or host sends (Cloudflare CF-IPCountry, CloudFront, Vercel, App Engine, nginx/Apache GeoIP). If there is none and a lookup service is chosen in Settings → Visitor details, the visitor's IP address is sent to that service (country.is, ipapi.co or ipinfo.io) by WP-cron shortly after the event. No lookup is made unless you choose a service. The IP address is deleted once the country is known, unless "Keep IP addresses" is on.
* The log is cleared after the retention period (365 days by default) and is included in WordPress's personal data export and erase tools. Suggested privacy policy text is added under Settings → Privacy.

* Page caching: the library and document pages show the visitor's own like state and a REST nonce. Exclude them from full-page caching for signed-in users (most cache plugins do this by default).
* The library page loads every document and searches and pages in the browser. For libraries of several thousand documents, use the shortcode with group or tag filters.

* Resources → Activity report with filters, breakdowns and CSV export; per-document activity box.
* Visitor details settings (country source, lookup service, IP handling, bots, retention).
* personal data export and erase for the activity log; privacy policy text.

* First release.1.0.0
