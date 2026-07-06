<?php
/**
 * adlite configuration.
 *
 * This is the ONLY file you normally need to touch. Point it at any news
 * outlet's public RSS feed and the generator produces a text-only "lite"
 * site in the same spirit as noslite.
 *
 * IMPORTANT (paywalls): this reads a *public* RSS feed. It shows exactly the
 * content the publisher chooses to syndicate for free (headlines + summaries,
 * and full text where the feed provides it). It does not, and is not meant to,
 * unlock premium/paywalled articles.
 */

return [
    // Human-readable site name, used in <title> and headings.
    'name' => 'AD lite',

    // Short tagline shown nowhere by default but handy for your README.
    'tagline' => 'Tekstversie van ad.nl. Geen plaatjes, video\'s, scripts, trackers of cookies.',

    // The public RSS/Atom feed to read.
    //
    // AD.nl publishes several feeds. The homepage feed is the closest match to
    // what noslite does for NOS. If one 404s, try one of the alternatives.
    //   General / homepage : https://www.ad.nl/home/rss.xml
    //   Binnenland         : https://www.ad.nl/binnenland/rss.xml
    //   Buitenland         : https://www.ad.nl/buitenland/rss.xml
    //   Sport              : https://www.ad.nl/sport/rss.xml
    // Confirm the current URL in your browser first (open it, you should see XML).
    // Can be overridden at runtime with the ADLITE_FEED_URL environment variable.
    'feed_url' => getenv('ADLITE_FEED_URL') ?: 'https://www.ad.nl/home/rss.xml',

    // The source homepage, linked in the footer.
    'source_name' => 'ad.nl',
    'source_url'  => 'https://www.ad.nl/',

    // Language and theme colour for the <html lang> and <meta theme-color>.
    // (NOS uses #d02527. Set this to whatever AD is currently using; the value
    //  below is a reasonable AD-blue placeholder.)
    'lang'        => 'nl',
    'theme_color' => '#0a5ad4',

    // Timezone used for the "last update" stamp.
    'timezone'    => 'Europe/Amsterdam',

    // A polite User-Agent. Some feeds reject the default PHP UA.
    'user_agent'  => 'adlite/1.0 (+personal text-only reader; https://github.com/yourname/adlite)',
];
