<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use PDO;

final class EmailRenderer
{
    public function __construct(private PDO $db) {}

    public static function blankContent(): array
    {
        return [
            'theme' => ['background' => '#fdfafa', 'body' => '#ffffff', 'text' => '#264653', 'accent' => '#a1c63e', 'font' => 'Arial, sans-serif', 'width' => 600],
            'blocks' => [
                ['type' => 'heading', 'text' => 'Your heading', 'level' => 1, 'align' => 'left'],
                ['type' => 'text', 'html' => '<p>Write your message here.</p>', 'align' => 'left'],
                ['type' => 'button', 'text' => 'Learn more', 'url' => 'https://', 'align' => 'left'],
            ],
        ];
    }

    public static function defaultMasterHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>@media(max-width:620px){.email-shell{width:100%!important}.email-pad{padding-left:20px!important;padding-right:20px!important}.email-columns td{display:block!important;width:100%!important;box-sizing:border-box}}</style>
</head>
<body style="margin:0;padding:0;background:{{background_colour}};font-family:{{font}};color:{{text_colour}};">
{{preheader}}
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:{{background_colour}};">
<tr><td align="center" style="padding:28px 12px;">
<table role="presentation" class="email-shell" width="{{width}}" cellspacing="0" cellpadding="0" border="0" style="width:{{width}}px;max-width:100%;background:{{body_colour}};border-radius:8px;overflow:hidden;">
{{logo}}
{{content}}
{{footer}}
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    public static function masterHtmlError(string $html): ?string
    {
        foreach (['{{logo}}', '{{content}}', '{{footer}}'] as $placeholder) {
            if (!str_contains($html, $placeholder)) return "Master HTML must contain $placeholder.";
        }
        if (preg_match('/<\s*script\b|javascript\s*:|\son[a-z]+\s*=/i', $html)) {
            return 'Master HTML cannot contain scripts, JavaScript URLs, or inline event handlers.';
        }
        return null;
    }

    /** @return array{html:string,text:string} */
    public function render(array|string $content, array $recipient = [], ?string $unsubscribeUrl = null): array
    {
        if (is_string($content)) $content = json_decode($content, true) ?: self::blankContent();
        $theme = array_merge(self::blankContent()['theme'], is_array($content['theme'] ?? null) ? $content['theme'] : []);
        $accent = $this->colour($theme['accent'] ?? '#a1c63e', '#a1c63e');
        $background = $this->colour($theme['background'] ?? '#fdfafa', '#fdfafa');
        $body = $this->colour($theme['body'] ?? '#ffffff', '#ffffff');
        $text = $this->colour($theme['text'] ?? '#264653', '#264653');
        $width = max(480, min(680, (int)($theme['width'] ?? 600)));
        $fonts = ['Arial, sans-serif', 'Georgia, serif', 'Verdana, sans-serif', 'Tahoma, sans-serif'];
        $font = in_array($theme['font'] ?? '', $fonts, true) ? $theme['font'] : $fonts[0];

        $rows = '';
        $plain = [];
        foreach (($content['blocks'] ?? []) as $block) {
            if (!is_array($block)) continue;
            [$blockHtml, $blockText] = $this->renderBlock($block, $accent, $text);
            if ($blockHtml !== '') $rows .= '<tr><td style="padding:12px 32px;">' . $blockHtml . '</td></tr>';
            if ($blockText !== '') $plain[] = $blockText;
        }

        $config = $this->config();
        $businessName = trim((string)($config['business_name'] ?? ''));
        if ($businessName === '') $businessName = trim((string)($config['from_name'] ?? ''));
        if ($businessName === '') $businessName = 'Coysh Digital';
        $business = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
        $address = nl2br(htmlspecialchars((string)($config['business_address'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $privacy = $this->safeUrl((string)($config['privacy_url'] ?? ''));
        $unsubscribeUrl ??= '#';
        $safeUnsub = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');
        $footer = '<tr><td style="padding:24px 32px;border-top:1px solid #d5ebf0;color:#526b74;font-size:12px;line-height:1.6;text-align:center;">'
            . $business . ($address !== '' ? '<br>' . $address : '')
            . '<br><a href="' . $safeUnsub . '" style="color:#264653;text-decoration:underline;">Unsubscribe from marketing emails</a>'
            . ($privacy ? ' &nbsp;·&nbsp; <a href="' . htmlspecialchars($privacy, ENT_QUOTES, 'UTF-8') . '" style="color:#264653;text-decoration:underline;">Privacy</a>' : '')
            . '</td></tr>';

        $preheader = htmlspecialchars((string)($content['preheader'] ?? ''), ENT_QUOTES, 'UTF-8');
        $logoUrl = $this->publicUrl((string)($config['logo_url'] ?? ''));
        $safeLogoUrl = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $logo = '<tr><td style="padding:28px 32px 18px;">'
            . '<a href="https://coysh.digital" style="text-decoration:none;"><img src="' . $safeLogoUrl . '" width="380" height="90" alt="Coysh Digital" style="display:block;width:380px;max-width:100%;height:auto;border:0;"></a></td></tr>';
        $master = trim((string)($config['master_html'] ?? ''));
        if ($master === '' || self::masterHtmlError($master) !== null) $master = self::defaultMasterHtml();
        $html = strtr($master, [
            '{{background_colour}}' => $background,
            '{{body_colour}}' => $body,
            '{{text_colour}}' => $text,
            '{{accent_colour}}' => $accent,
            '{{font}}' => htmlspecialchars($font, ENT_QUOTES, 'UTF-8'),
            '{{width}}' => (string)$width,
            '{{preheader}}' => $preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $preheader . '</div>' : '',
            '{{logo}}' => $logo,
            '{{content}}' => $rows,
            '{{footer}}' => $footer,
        ]);

        $plain[] = html_entity_decode(strip_tags(str_replace('<br>', "\n", $business . ($address ? "\n" . $address : ''))));
        $plain[] = 'Unsubscribe: ' . $unsubscribeUrl;
        if ($privacy) $plain[] = 'Privacy: ' . $privacy;
        $rendered = ['html' => $html, 'text' => trim(implode("\n\n", array_filter($plain)))];
        return $this->personalise($rendered, $recipient, $unsubscribeUrl);
    }

    private function renderBlock(array $block, string $accent, string $textColour): array
    {
        $type = $block['type'] ?? '';
        $align = in_array($block['align'] ?? '', ['left', 'center', 'right'], true) ? $block['align'] : 'left';
        if ($type === 'heading') {
            $level = in_array((int)($block['level'] ?? 2), [1, 2, 3], true) ? (int)$block['level'] : 2;
            $sizes = [1 => 30, 2 => 24, 3 => 19];
            $value = htmlspecialchars((string)($block['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            return ['<h' . $level . ' style="margin:0;color:' . $textColour . ';font-size:' . $sizes[$level] . 'px;line-height:1.25;text-align:' . $align . ';">' . $value . '</h' . $level . '>', strip_tags($value)];
        }
        if ($type === 'text') {
            $safe = $this->sanitiseRichText((string)($block['html'] ?? ''));
            return ['<div style="font-size:16px;line-height:1.65;text-align:' . $align . ';">' . $safe . '</div>', trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ["\n\n", "\n", "\n", "\n", "\n"], $safe))))];
        }
        if ($type === 'button') {
            $url = $this->safeUrl((string)($block['url'] ?? ''));
            if (!$url) return ['', ''];
            $label = htmlspecialchars((string)($block['text'] ?? 'Learn more'), ENT_QUOTES, 'UTF-8');
            return ['<div style="text-align:' . $align . ';"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:' . $accent . ';color:' . $this->contrastColour($accent) . ';text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:6px;">' . $label . '</a></div>', html_entity_decode($label) . ': ' . $url];
        }
        if ($type === 'image') {
            $asset = $this->asset((int)($block['asset_id'] ?? 0));
            if (!$asset) return ['', ''];
            $url = appUrl() . '/email/assets/' . rawurlencode($asset['public_token']) . '/' . rawurlencode($asset['original_name']);
            $alt = htmlspecialchars((string)($block['alt'] ?? $asset['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8');
            $img = '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:6px;">';
            $link = $this->safeUrl((string)($block['url'] ?? ''));
            if ($link) $img = '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . $img . '</a>';
            return ['<div style="text-align:' . $align . ';">' . $img . '</div>', $alt];
        }
        if ($type === 'divider') return ['<hr style="border:0;border-top:1px solid #e2e8f0;margin:4px 0;">', ''];
        if ($type === 'spacer') {
            $height = max(8, min(64, (int)($block['height'] ?? 24)));
            return ['<div style="height:' . $height . 'px;line-height:' . $height . 'px;">&nbsp;</div>', ''];
        }
        if ($type === 'columns') {
            $cells = [];
            $texts = [];
            foreach (array_slice((array)($block['columns'] ?? []), 0, 2) as $column) {
                $inner = '';
                foreach ((array)($column['blocks'] ?? []) as $child) {
                    [$h, $t] = $this->renderBlock((array)$child, $accent, $textColour);
                    $inner .= '<div style="margin-bottom:12px;">' . $h . '</div>';
                    if ($t) $texts[] = $t;
                }
                $cells[] = '<td width="50%" valign="top" style="width:50%;padding:8px;">' . $inner . '</td>';
            }
            return ['<table role="presentation" class="email-columns" width="100%" cellspacing="0" cellpadding="0"><tr>' . implode('', $cells) . '</tr></table>', implode("\n", $texts)];
        }
        return ['', ''];
    }

    private function sanitiseRichText(string $html): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $doc->getElementById('root');
        if (!$root) return '';
        $allowed = ['div', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a'];
        $walk = function (DOMNode $node) use (&$walk, $allowed, $doc): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    $tag = strtolower($child->tagName);
                    if (!in_array($tag, $allowed, true)) {
                        if (in_array($tag, ['script', 'style'], true)) $node->removeChild($child);
                        else $node->replaceChild($doc->createTextNode($child->textContent ?? ''), $child);
                        continue;
                    }
                    $originalHref = $tag === 'a' ? $child->getAttribute('href') : '';
                    foreach (iterator_to_array($child->attributes) as $attribute) $child->removeAttribute($attribute->name);
                    if ($tag === 'a') {
                        $href = $this->safeUrl($originalHref);
                        if ($href) $child->setAttribute('href', $href);
                    }
                    $walk($child);
                }
            }
        };
        $walk($root);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $url === 'https://') return null;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true) ? $url : null;
    }

    private function colour(string $colour, string $fallback): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $colour) ? strtolower($colour) : $fallback;
    }

    private function contrastColour(string $colour): string
    {
        $red = hexdec(substr($colour, 1, 2));
        $green = hexdec(substr($colour, 3, 2));
        $blue = hexdec(substr($colour, 5, 2));
        return (($red * 299 + $green * 587 + $blue * 114) / 1000) > 155 ? '#264653' : '#ffffff';
    }

    private function publicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return appUrl() . '/coysh-digital-email-logo.png';
        if (str_starts_with($url, '/')) return appUrl() . $url;
        return strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' && filter_var($url, FILTER_VALIDATE_URL)
            ? $url
            : (appUrl() . '/coysh-digital-email-logo.png');
    }

    private function config(): array
    {
        try { return $this->db->query('SELECT * FROM email_marketing_config WHERE id=1')->fetch() ?: []; }
        catch (\Throwable) { return []; }
    }

    private function asset(int $id): ?array
    {
        if (!$id) return null;
        $stmt = $this->db->prepare('SELECT * FROM email_assets WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function personalise(array $rendered, array $recipient, string $unsubscribeUrl): array
    {
        $replace = [
            '{{name}}' => (string)($recipient['name'] ?? ''),
            '{{company}}' => (string)($recipient['company_name'] ?? ''),
            '{{unsubscribe_url}}' => $unsubscribeUrl,
        ];
        return ['html' => strtr($rendered['html'], array_map(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $replace)), 'text' => strtr($rendered['text'], $replace)];
    }
}
