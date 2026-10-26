# Sepo Resources

A document library for WordPress. It holds PDFs, Word files, spreadsheets and slides, with search, groups, pages, likes, download counts, sign-in-only downloads and PDF preview.

<p align="center">
  <a href="https://edu.sepodesk.com/sponsor/projects-maintenance/">
    <img src="https://img.shields.io/badge/%E2%9D%A4%20SUPPORT%20OUR%20OPEN--SOURCE%20PROJECTS-0b6b45?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Support Our Open-Source Projects" />
  </a>
</p>

<p align="center">
  Your support helps us maintain, improve and build open-source software for everyone.
  <br />
  <strong>Every contribution makes a difference. Thank you for supporting our work!</strong>
</p>


![Version](https://img.shields.io/badge/version-1.1.2-0b3d33)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Shortcode](#shortcode)
- [Visitor details and privacy](#visitor-details-and-privacy)
- [Uninstalling](#uninstalling)
- [FAQ](#faq)
- [For developers](#for-developers)
- [Changelog](#changelog)
- [License](#license)

## Features

- **Library pages.** The library page is at `/resources/`, with group and keyword pages too. You can change the address in **Resources → Settings**.
- **Document pages.** Each document has:
  - a file, either uploaded or linked;
  - an author block and details;
  - a citation line;
  - a PDF preview;
  - related documents.
- **Download rules.** Every download goes through `/resources/download/{id}/`. That address applies the download rules, such as sign-in only, and counts the download once per visitor per time window.
- **Protected files.** Uploaded documents are stored in `wp-content/uploads/sepo-resources/`. On Apache and LiteSpeed they are blocked from direct access.
- **Likes and download counts** on every document.
- **Activity log.** Every like, unlike and download is recorded with:
  - the country;
  - the browser and its version;
  - the operating system;
  - the device type (computer, phone, tablet, TV or bot).

  See **Resources → Activity** for filters, breakdowns and CSV export. Each document also has a **Who liked and downloaded it** box.
- **Bot detection.** Bots, crawlers, link previews and scripts are recognised. Their downloads are logged but not counted, unless you turn on **Count downloads by bots**.
- **Theme colours and dark mode.** Colours follow your theme's design tokens when it has them, including its dark mode. Text keeps strong contrast on dark pages. An accent colour chosen in Settings overrides the theme's.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |

## Installation

### Upload the zip (recommended)

1. Download `sepo-resources.zip`.
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**.

### Upload the folder

1. Unzip the download.
2. Copy the `sepo-resources` folder into `wp-content/plugins/` on your server.
3. Go to **Plugins** and activate **Sepo Resources**.

> [!NOTE]
> GitHub's **Code → Download ZIP** names the folder after the branch, for example `sepo-resources-main`. Rename it to `sepo-resources` before uploading.

## Getting started

1. Go to **Resources → Add New** and add a document. Upload a file or paste a link, then fill in the details.
2. Sort documents into groups and keywords.
3. Open **Resources → Settings** to set the library address, the download rules and the accent colour.
4. Visit `/resources/` to see the library.

> [!IMPORTANT]
> On **nginx**, the protection files the plugin writes are ignored. Add the rule shown on **Resources → Settings** to your server block so uploaded files cannot be opened directly. Your host can add it for you.

## Shortcode

You can show a list of documents on any page:

```text
[sepo_resources group="slug" tag="slug" ids="1,2" per_page="10" limit="-1" search="no" groups="no"]
```

| Option | Meaning |
|---|---|
| `group` | Show only one group (its slug). |
| `tag` | Show only one keyword (its slug). |
| `ids` | Show only these documents. |
| `per_page` | Documents per page. |
| `limit` | Most documents to load; `-1` means all. |
| `search` | `no` hides the search box. |
| `groups` | `no` hides the group filter. |

## Visitor details and privacy

You choose how visitor details are collected under **Resources → Settings → Visitor details**.

- **Country.** The country comes first from the header your CDN or host sends, such as Cloudflare, CloudFront, Vercel, App Engine, or nginx or Apache GeoIP.
  - If there is no header and you choose a lookup service (country.is, ipapi.co or ipinfo.io), the visitor's IP address is sent to that service shortly after the event.
  - **No lookup is made unless you choose a service.**
- **IP addresses.** An IP address is deleted once its country is known, unless **Keep IP addresses** is on.
- **Retention.** The log is cleared after the retention period, 365 days by default.
- **Personal data.** The log is included in WordPress's personal data export and erase tools. Suggested privacy policy text is added under **Settings → Privacy**.

## Uninstalling

**Deactivating** the plugin (**Plugins → Deactivate**) stops it and keeps everything.

**Deleting** the plugin (**Plugins → Deactivate**, then **Delete**) removes the plugin files.

- **Your documents and their files are never deleted.** They are ordinary WordPress content and stay on your site.
- **By default likes, counts and settings are kept too.**
- **To remove them**, first tick **Delete likes, download counts, the activity log and settings** under **Resources → Settings** and save, then delete the plugin. This removes:
  - the like and download counts;
  - the activity log;
  - cached country lookups;
  - the settings.

## FAQ

**Does it work with page caching?**
Yes. The library and document pages show each visitor's own like state, so exclude them from full-page caching for signed-in users. Most cache plugins do this by default.

**How big can the library get?**
The library page loads every document and does its search and pages in the browser. For several thousand documents, use the shortcode with group or keyword filters.

## For developers

- **Theme overrides.** Copy `templates/archive.php`, `templates/single.php` or `templates/parts/*.php` into `your-theme/sepo-resources/`.
- **REST API** under `/wp-json/sepo-resources/v1`:
  - `GET /stats`
  - `POST /downloaded`
  - `POST /like`
  - `POST /reset`
- **Filters:**
  - `sepo_resources_document` (`$doc` array, `WP_Post`) changes what the views receive.
  - `sepo_resources_country_lookup` (`null`, `$ip`) lets you return a two-letter country code yourself, for example from a local MaxMind database.
- **Action:** `sepo_resources_activity` (`$id`, `$kind`, `$visitor`) runs after each logged event.

## Changelog

### 1.1.2
- In dark mode, body and secondary text use light colours with strong contrast (about 14:1 and 10:1). Dates, summaries, labels and counts read as clearly as the headings.

### 1.1.1
- Colours follow the theme's design tokens when it has them, including its dark mode. An accent chosen in Settings still overrides the theme's.
- On dark pages, primary buttons use the theme's dark-mode button colours, or a lighter version of a custom accent with dark text.
- Secondary text uses a muted colour with readable contrast in light and dark mode.
- A failed like shows a message in the page.

### 1.1.0
- Activity log with country, browser, system and device for every like, unlike and download.
- **Resources → Activity** report with filters, breakdowns and CSV export, and an activity box on each document.
- Visitor details settings: country source, lookup service, IP handling, bots and retention.
- Downloads by bots and scripts are left out of totals. A setting restores counting them.
- Personal data export and erase for the activity log, and privacy policy text.

### 1.0.0
- First release.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
