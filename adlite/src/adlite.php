<?php
/**
 * adlite — a text-only static mirror of a public news RSS feed.
 *
 * Design is a straight descendant of noslite (https://github.com/noslite/noslite):
 * read a public feed, throw away images / video / scripts / trackers, render
 * plain static HTML, and persist a gzipped copy next to each file.
 *
 * Differences from the original:
 *   - No Composer/Twig dependency (pure PHP, so `php src/adlite.php` just works).
 *   - Outlet-agnostic: everything lives in src/config.php.
 *   - Article ids are derived from the feed's guid/link (a short stable hash)
 *     instead of NOS's fixed URL shape, so it works for any outlet.
 *   - The feed summary HTML is run through a strict tag whitelist.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

/** Thrown when we can't build the site from the feed. */
final class Unavailable extends Exception {}

/* --------------------------------------------------------------------------
 * Small helpers (mirrors of noslite's path / internal_link / safe_* helpers)
 * ------------------------------------------------------------------------ */

function project_path(string $path): string
{
    return __DIR__ . '/..' . $path;
}

/** Stable, filesystem-safe id for an article, derived from guid or link. */
function derive_id(string $guidOrLink): string
{
    $guidOrLink = trim($guidOrLink);
    if ($guidOrLink === '') {
        throw new Unavailable('Empty guid/link, cannot derive id');
    }
    // If the source already ends in a numeric id, keep it (nicer URLs);
    // otherwise fall back to a short hash of the whole thing.
    if (preg_match('/(\d{4,})\D*$/', $guidOrLink, $m)) {
        return $m[1];
    }
    return substr(sha1($guidOrLink), 0, 12);
}

function internal_link(string $id): string
{
    return '/l/' . $id . '.html';
}

function safe_get_contents(string $url, string $userAgent): string
{
    $context = stream_context_create([
        'http' => [
            'header'        => "User-Agent: {$userAgent}\r\nAccept: application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.8\r\n",
            'timeout'       => 15,
            'follow_location' => 1,
        ],
        'https' => [
            'header'        => "User-Agent: {$userAgent}\r\nAccept: application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.8\r\n",
            'timeout'       => 15,
            'follow_location' => 1,
        ],
    ]);

    $contents = @file_get_contents($url, false, $context);
    if ($contents === false) {
        throw new Unavailable('Could not fetch ' . $url);
    }
    return $contents;
}

function safe_xml_element(string $string): SimpleXMLElement
{
    libxml_use_internal_errors(true);
    try {
        $xml = new SimpleXMLElement($string, LIBXML_NOCDATA);
    } catch (Exception $e) {
        libxml_clear_errors();
        throw new Unavailable('Malformed feed XML: ' . $e->getMessage());
    }
    libxml_clear_errors();
    return $xml;
}

/* --------------------------------------------------------------------------
 * Text-only sanitiser: keep prose, drop everything that carries media,
 * scripts, styling or tracking.
 * ------------------------------------------------------------------------ */

const ALLOWED_TAGS = [
    'p', 'br', 'a', 'ul', 'ol', 'li',
    'strong', 'b', 'em', 'i', 'h2', 'h3', 'h4',
    'blockquote', 'figcaption',
];

// Removed together with everything inside them (never unwrapped).
const DROP_TAGS = [
    'script', 'style', 'noscript', 'iframe', 'template',
    'svg', 'object', 'embed', 'form', 'video', 'audio', 'canvas',
];

function sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // Wrap so we always have a single root; force UTF-8.
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    $root = $doc->getElementById('__root');
    if ($root === null) {
        return strip_tags($html, '<p><a><strong><em>');
    }

    clean_node($doc, $root);

    // Serialise inner HTML of the root.
    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

function clean_node(DOMDocument $doc, DOMNode $node): void
{
    // Iterate over a static copy because we mutate the tree.
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);

            if (in_array($tag, DROP_TAGS, true)) {
                // Remove the element and everything inside it.
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, ALLOWED_TAGS, true)) {
                // Unwrap: keep the text/children, drop the disallowed element.
                clean_node($doc, $child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            // Strip every attribute except href on <a>.
            foreach (iterator_to_array($child->attributes) as $attr) {
                $keep = ($tag === 'a' && strtolower($attr->name) === 'href'
                    && preg_match('#^https?://#i', $attr->value));
                if (!$keep) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a') {
                // Open source links in the same tab; no referrer leakage.
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            clean_node($doc, $child);
        } elseif ($child instanceof DOMComment) {
            $node->removeChild($child);
        }
    }
}

/* --------------------------------------------------------------------------
 * Feed parsing
 * ------------------------------------------------------------------------ */

function feed_items(SimpleXMLElement $rss): array
{
    $items = [];

    // RSS 2.0 (<rss><channel><item>) — the AD/NOS shape.
    $nodes = $rss->channel->item ?? null;
    // Atom fallback (<feed><entry>).
    if ($nodes === null && isset($rss->entry)) {
        $nodes = $rss->entry;
    }
    if ($nodes === null) {
        throw new Unavailable('No <item>/<entry> elements found in feed');
    }

    foreach ($nodes as $item) {
        $link = (string) ($item->link->attributes()['href'] ?? $item->link);
        $guid = (string) ($item->guid ?? '');
        $id   = derive_id($guid !== '' ? $guid : $link);

        // Prefer full content:encoded when present, else description/summary.
        $contentNs = $item->children('content', true);
        $raw = '';
        if (isset($contentNs->encoded) && trim((string) $contentNs->encoded) !== '') {
            $raw = (string) $contentNs->encoded;
        } elseif (isset($item->description)) {
            $raw = (string) $item->description;
        } elseif (isset($item->summary)) {
            $raw = (string) $item->summary;
        }

        $title = trim(html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $body  = sanitize_html($raw);
        if ($body === '') {
            $body = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $items[] = [
            'id'            => $id,
            'internal_link' => internal_link($id),
            'external_link' => $link,
            'title'         => $title,
            'content'       => $body,
        ];
    }

    return $items;
}

/* --------------------------------------------------------------------------
 * Rendering (tiny template engine: {{ placeholders }} in templates/*.html)
 * ------------------------------------------------------------------------ */

function render(string $templateFile, array $vars): string
{
    $tpl = file_get_contents(project_path('/templates/' . $templateFile));
    if ($tpl === false) {
        throw new Unavailable('Missing template ' . $templateFile);
    }
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '';
    }, $tpl);
}

function render_index(array $items, array $config): string
{
    $lis = '';
    foreach ($items as $item) {
        $lis .= '<li><a href="' . htmlspecialchars($item['internal_link'], ENT_QUOTES) . '">'
              . $item['title'] . '</a></li>' . "\n      ";
    }
    return render('index.html', [
        'name'        => htmlspecialchars($config['name'], ENT_QUOTES),
        'lang'        => htmlspecialchars($config['lang'], ENT_QUOTES),
        'theme_color' => htmlspecialchars($config['theme_color'], ENT_QUOTES),
        'items'       => trim($lis),
        'updated'     => date('H:i'),
        'source_name' => htmlspecialchars($config['source_name'], ENT_QUOTES),
        'source_url'  => htmlspecialchars($config['source_url'], ENT_QUOTES),
    ]);
}

function render_article(array $item, array $config): string
{
    return render('article.html', [
        'name'          => htmlspecialchars($config['name'], ENT_QUOTES),
        'lang'          => htmlspecialchars($config['lang'], ENT_QUOTES),
        'theme_color'   => htmlspecialchars($config['theme_color'], ENT_QUOTES),
        'title'         => $item['title'],
        'content'       => $item['content'],
        'external_link' => htmlspecialchars($item['external_link'], ENT_QUOTES),
        'source_name'   => htmlspecialchars($config['source_name'], ENT_QUOTES),
    ]);
}

/** Write a file plus a gzipped copy (as noslite does), creating dirs as needed. */
function persist(string $path, string $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $data);
    file_put_contents($path . '.gz', gzencode($data, 9));
}

/* --------------------------------------------------------------------------
 * Main
 * ------------------------------------------------------------------------ */

try {
    $xml   = safe_xml_element(safe_get_contents($config['feed_url'], $config['user_agent']));
    $items = feed_items($xml);

    if ($items === []) {
        throw new Unavailable('Feed contained zero usable items');
    }

    persist(project_path('/site/index.html'), render_index($items, $config));

    foreach ($items as $item) {
        persist(project_path('/site' . $item['internal_link']), render_article($item, $config));
    }

    fwrite(STDERR, sprintf("adlite: wrote %d articles + index at %s\n", count($items), date('c')));
} catch (Unavailable $e) {
    fwrite(STDERR, 'adlite failed: ' . $e->getMessage() . "\n");
    exit(1);
}
