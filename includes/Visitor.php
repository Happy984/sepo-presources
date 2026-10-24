<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * Who is making the current request: browser, system, device type, whether
 * it is a bot, the IP address and — when the host or CDN says so — the
 * country. Parsing is done here, with no outside library.
 */
final class Visitor
{
    /** Request headers set by CDNs and hosts that already know the country. */
    private const COUNTRY_HEADERS = [
        'HTTP_CF_IPCOUNTRY',               // Cloudflare
        'HTTP_CLOUDFRONT_VIEWER_COUNTRY',  // Amazon CloudFront
        'HTTP_X_VERCEL_IP_COUNTRY',        // Vercel
        'HTTP_X_APPENGINE_COUNTRY',        // Google App Engine
        'HTTP_FASTLY_GEO_COUNTRY_CODE',    // Fastly (when configured)
        'HTTP_X_COUNTRY_CODE',             // common on managed hosts / nginx GeoIP
        'HTTP_X_GEO_COUNTRY',
        'GEOIP_COUNTRY_CODE',              // Apache / nginx GeoIP module
        'MM_COUNTRY_CODE',                 // MaxMind module
    ];

    /** @var array<string,mixed>|null */
    private static ?array $current = null;

    /**
     * Everything known about the visitor making this request.
     *
     * @return array{browser:string,browser_version:string,os:string,device:string,bot:bool,user_agent:string,ip:string,country:string}
     */
    public static function current(): array
    {
        if (self::$current === null) {
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            self::$current = self::parse($ua) + [
                'ip'      => self::ip(),
                'country' => self::headerCountry(),
            ];
        }
        return self::$current;
    }

    /** Forget the cached visitor (tests, long-running processes). */
    public static function reset(): void
    {
        self::$current = null;
    }

    public static function isBot(): bool
    {
        return self::current()['bot'];
    }

    /**
     * The visitor's IP address, read from the source chosen in settings.
     * REMOTE_ADDR is the only one a visitor cannot fake; the others are
     * right only when the site really sits behind that proxy.
     */
    public static function ip(): string
    {
        $source = (string)Settings::get('ip_source');
        $raw = '';
        if ($source !== 'REMOTE_ADDR' && in_array($source, ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP'], true)) {
            $raw = isset($_SERVER[$source]) ? (string)wp_unslash($_SERVER[$source]) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            if ($source === 'HTTP_X_FORWARDED_FOR') {
                $raw = trim(explode(',', $raw)[0]);   // first hop is the visitor
            }
        }
        if ($raw === '' || filter_var(trim($raw), FILTER_VALIDATE_IP) === false) {
            $raw = isset($_SERVER['REMOTE_ADDR']) ? (string)wp_unslash($_SERVER['REMOTE_ADDR']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        $raw = trim($raw);
        return filter_var($raw, FILTER_VALIDATE_IP) !== false ? $raw : '';
    }

    /** True for addresses an outside lookup could place on a map. */
    public static function isPublicIp(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** Two-letter country from a CDN/host header, or '' when there is none. */
    public static function headerCountry(): string
    {
        if (!(int)Settings::get('geo_header')) {
            return '';
        }
        foreach (self::COUNTRY_HEADERS as $h) {
            if (!empty($_SERVER[$h])) {
                $c = Countries::normalise((string)wp_unslash($_SERVER[$h])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                if ($c !== '') {
                    return $c;
                }
            }
        }
        return '';
    }

    /**
     * Read a user-agent string.
     *
     * @return array{browser:string,browser_version:string,os:string,device:string,bot:bool,user_agent:string}
     */
    public static function parse(string $ua): array
    {
        $ua = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $ua) ?? '');
        $out = [
            'browser'         => '',
            'browser_version' => '',
            'os'              => '',
            'device'          => 'desktop',
            'bot'             => false,
            'user_agent'      => mb_substr($ua, 0, 255),
        ];

        if ($ua === '') {
            $out['bot'] = true;
            $out['device'] = 'bot';
            $out['browser'] = __('No user agent', 'sepo-resources');
            return $out;
        }

        // ---- bots, crawlers, link previews and scripts
        $bot = self::botName($ua);
        if ($bot !== null) {
            $out['bot'] = true;
            $out['device'] = 'bot';
            $out['browser'] = $bot;
            $out['os'] = self::os($ua);
            return $out;
        }

        // ---- browser. Order matters: most of these also say "Chrome" and "Safari".
        $browsers = [
            ['FBAN|FBAV|FB_IAB',               'Facebook app',     '(?:FBAV)/([\d.]+)'],
            ['Instagram',                      'Instagram app',    'Instagram ([\d.]+)'],
            ['Opera Mini',                    'Opera Mini',       'Opera Mini/([\d.]+)'],
            ['OPR/|OPT/|OPiOS|Opera',          'Opera',            '(?:OPR|OPT|OPiOS|Version|Opera)/([\d.]+)'],
            ['Edg/|EdgA/|EdgiOS/|Edge/',       'Edge',             '(?:Edg|EdgA|EdgiOS|Edge)/([\d.]+)'],
            ['SamsungBrowser',                 'Samsung Internet', 'SamsungBrowser/([\d.]+)'],
            ['UCBrowser|UCWEB',                'UC Browser',       'UCBrowser/([\d.]+)'],
            ['YaBrowser',                      'Yandex',           'YaBrowser/([\d.]+)'],
            ['Vivaldi',                        'Vivaldi',          'Vivaldi/([\d.]+)'],
            ['Brave',                          'Brave',            'Chrome/([\d.]+)'],
            ['MiuiBrowser',                    'Mi Browser',       'MiuiBrowser/([\d.]+)'],
            ['HuaweiBrowser',                  'Huawei Browser',   'HuaweiBrowser/([\d.]+)'],
            ['PHX/',                           'Phoenix',          'PHX/([\d.]+)'],
            ['KAIOS',                          'KaiOS Browser',    'KAIOS/([\d.]+)'],
            ['Firefox/|FxiOS/',                'Firefox',          '(?:Firefox|FxiOS)/([\d.]+)'],
            ['CriOS/',                         'Chrome',           'CriOS/([\d.]+)'],
            ['; wv\)',                         'Android WebView',  'Chrome/([\d.]+)'],
            ['Chrome/|Chromium/',              'Chrome',           '(?:Chrome|Chromium)/([\d.]+)'],
            ['Safari/(?=.*Version/)|Version/[\d.]+.*Safari', 'Safari', 'Version/([\d.]+)'],
            ['MSIE |Trident/',                 'Internet Explorer','(?:MSIE |rv:)([\d.]+)'],
            ['AppleWebKit.*Mobile/',           'Safari (in app)',  ''],
        ];
        foreach ($browsers as [$test, $name, $ver]) {
            if (preg_match('#' . $test . '#i', $ua) === 1) {
                $out['browser'] = $name;
                if ($ver !== '' && preg_match('#' . $ver . '#i', $ua, $m) === 1) {
                    $out['browser_version'] = self::major($m[1]);
                }
                break;
            }
        }
        if ($out['browser'] === '') {
            $out['browser'] = __('Other', 'sepo-resources');
        }

        $out['os'] = self::os($ua);

        // ---- device type
        if (preg_match('/iPad|Tablet|Tab(?!le)|Kindle|Silk|PlayBook/i', $ua) === 1
            || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
            $out['device'] = 'tablet';
        } elseif (preg_match('/Mobi|iPhone|iPod|Android|KAIOS|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile/i', $ua) === 1) {
            $out['device'] = 'mobile';
        }
        if (preg_match('/SmartTV|SMART-TV|HbbTV|AppleTV|GoogleTV|CrKey|Tizen.*TV|Web0S|NetCast/i', $ua) === 1) {
            $out['device'] = 'tv';
        }

        return $out;
    }

    /** The bot's name when this agent is a crawler, preview fetcher or script. */
    private static function botName(string $ua): ?string
    {
        $named = [
            'Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google' => 'Googlebot',
            'AdsBot-Google|Mediapartners-Google'   => 'Google Ads bot',
            'bingbot|BingPreview|msnbot'           => 'Bingbot',
            'YandexBot|YandexImages'               => 'YandexBot',
            'Baiduspider'                          => 'Baidu spider',
            'DuckDuckBot|DuckAssistBot'            => 'DuckDuckBot',
            'Applebot'                             => 'Applebot',
            'facebookexternalhit|Facebot|meta-externalagent' => 'Facebook crawler',
            'Twitterbot'                           => 'X (Twitter) bot',
            'LinkedInBot'                          => 'LinkedIn bot',
            'Slackbot|Slack-ImgProxy'              => 'Slack preview',
            'TelegramBot'                          => 'Telegram preview',
            'WhatsApp/[\d.]+ [AiNW]?$|WhatsApp/[\d.]+$' => 'WhatsApp preview',
            'Discordbot'                           => 'Discord preview',
            'GPTBot|ChatGPT-User|OAI-SearchBot'    => 'OpenAI bot',
            'ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai' => 'Anthropic bot',
            'PerplexityBot|Perplexity-User'        => 'Perplexity bot',
            'CCBot'                                => 'Common Crawl',
            'Bytespider'                           => 'ByteDance spider',
            'AhrefsBot'                            => 'Ahrefs bot',
            'SemrushBot'                           => 'Semrush bot',
            'MJ12bot'                              => 'Majestic bot',
            'DotBot'                               => 'Moz bot',
            'PetalBot'                             => 'Petal bot',
            'Lighthouse|Chrome-Lighthouse|PageSpeed' => 'Lighthouse',
            'HeadlessChrome'                       => 'Headless Chrome',
            '^curl/'                               => 'curl',
            '^Wget'                                => 'Wget',
            'python-requests|python-urllib|aiohttp|httpx' => 'Python script',
            'Go-http-client'                       => 'Go script',
            'okhttp'                               => 'OkHttp',
            'axios|node-fetch|undici'              => 'Node.js script',
            '^Java/|Apache-HttpClient'             => 'Java script',
            'libwww-perl'                          => 'Perl script',
            'WordPress/'                           => 'WordPress',
            'Scrapy'                               => 'Scrapy',
            'UptimeRobot|Pingdom|StatusCake|Site24x7' => 'Uptime monitor',
        ];
        foreach ($named as $re => $name) {
            if (preg_match('#' . $re . '#i', $ua) === 1) {
                return $name;
            }
        }
        // Generic words; phone makers that end in "bot" are removed first.
        $plain = preg_replace('/cubot/i', '', $ua) ?? $ua;
        if (preg_match('/bot\b|crawl|spider|slurp|scrap|fetcher|preview|monitor|validator|checker|archiver/i', $plain) === 1) {
            return __('Other bot', 'sepo-resources');
        }
        return null;
    }

    private static function os(string $ua): string
    {
        $v = static fn(string $s): string => str_replace('_', '.', $s);
        if (preg_match('/Windows Phone(?: OS)? ([\d.]+)/i', $ua, $m)) {
            return 'Windows Phone';
        }
        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            return match ($m[1]) {
                '10.0'  => 'Windows 10/11',
                '6.3'   => 'Windows 8.1',
                '6.2'   => 'Windows 8',
                '6.1'   => 'Windows 7',
                '6.0'   => 'Windows Vista',
                '5.1', '5.2' => 'Windows XP',
                default => 'Windows',
            };
        }
        if (stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (preg_match('/KAIOS\/?([\d.]*)/i', $ua, $m)) {
            return 'KaiOS';
        }
        if (preg_match('/Android[ \/]?([\d.]*)/i', $ua, $m)) {
            $major = self::major($m[1]);
            return $major !== '' ? 'Android ' . $major : 'Android';
        }
        if (preg_match('/HarmonyOS/i', $ua)) {
            return 'HarmonyOS';
        }
        if (preg_match('/iPad.*OS ([\d_]+)/i', $ua, $m)) {
            return 'iPadOS ' . self::major($v($m[1]));
        }
        if (preg_match('/(?:iPhone|iPod).*OS ([\d_]+)/i', $ua, $m)) {
            return 'iOS ' . self::major($v($m[1]));
        }
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/CrOS/i', $ua)) {
            return 'ChromeOS';
        }
        if (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/SymbOS|Symbian|Series ?60/i', $ua)) {
            return 'Symbian';
        }
        if (preg_match('/J2ME|MIDP/i', $ua)) {
            return 'Java phone';
        }
        if (preg_match('/Tizen/i', $ua)) {
            return 'Tizen';
        }
        if (preg_match('/BlackBerry|BB10/i', $ua)) {
            return 'BlackBerry';
        }
        if (preg_match('/Ubuntu/i', $ua)) {
            return 'Ubuntu';
        }
        if (preg_match('/Linux|X11/i', $ua)) {
            return 'Linux';
        }
        return '';
    }

    private static function major(string $version): string
    {
        $parts = explode('.', $version);
        return preg_replace('/\D/', '', $parts[0]) ?? '';
    }

    /** Human label for a device type. */
    public static function deviceLabel(string $device): string
    {
        return match ($device) {
            'mobile'  => __('Phone', 'sepo-resources'),
            'tablet'  => __('Tablet', 'sepo-resources'),
            'tv'      => __('TV', 'sepo-resources'),
            'bot'     => __('Bot', 'sepo-resources'),
            'desktop' => __('Computer', 'sepo-resources'),
            default   => __('Unknown', 'sepo-resources'),
        };
    }
}
