<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ClientFence branding: uploaded logos and icon, a brand color and the sign-in page style (the
 * setup wizard's branding step, Setup.php).
 *
 * - Uploads are checked, re-encoded as PNG (so nothing but pixels is kept) and saved under
 *   public/uploads/brand with a random name; company_logo, company_logo_dark and company_favicon
 *   point to them.
 * - A brand color other than the default writes a small stylesheet (public/uploads/brand/brand-*.css)
 *   that both themes load: it sets the client theme's --pal-* variables, Bootstrap's primary
 *   colors and the admin's accent. The default color writes nothing, so the themes stay as built.
 * - The sign-in page style (dark, brand or light) is a class on the client theme's sign-in panel.
 */
final class Branding
{
    public const string COLOR = 'clientfence_brand_color';
    public const string LOGIN_STYLE = 'clientfence_login_style';
    public const string CSS = 'clientfence_brand_css';
    public const string DEFAULT_COLOR = '#2672e3';
    public const array LOGIN_STYLES = ['dark', 'brand', 'light'];
    public const string DIR = 'public/uploads/brand';

    /** Upload kinds: the setting each one fills and the largest size it is scaled to. */
    public const array ASSETS = [
        'logo' => ['param' => 'company_logo', 'width' => 1200, 'height' => 400],
        'logo_dark' => ['param' => 'company_logo_dark', 'width' => 1200, 'height' => 400],
        'icon' => ['param' => 'company_favicon', 'width' => 512, 'height' => 512],
    ];
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, array{color: string, custom: bool, login_style: string, css_url: ?string}> */
    private static array $cache = [];

    /**
     * The brand for the templates: the color, whether it is a custom one, the sign-in style and the
     * stylesheet to load (null with the default color).
     *
     * @return array{color: string, custom: bool, login_style: string, css_url: ?string}
     */
    public static function get(\Pimple\Container $di): array
    {
        $key = spl_object_hash($di);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        try {
            $rows = $di['em']->getConnection()->fetchAllKeyValue('SELECT param, value FROM setting WHERE param IN (?, ?, ?)', [self::COLOR, self::LOGIN_STYLE, self::CSS]);
        } catch (\Throwable) {
            $rows = [];
        }
        $color = self::validColor((string) ($rows[self::COLOR] ?? '')) ?? self::DEFAULT_COLOR;
        $style = (string) ($rows[self::LOGIN_STYLE] ?? '');
        $css = (string) ($rows[self::CSS] ?? '');
        $cssOk = $css !== '' && preg_match('#\A' . preg_quote(self::DIR, '#') . '/brand-[a-f0-9]{12}\.css\z#', $css) === 1;

        return self::$cache[$key] = [
            'color' => $color,
            'custom' => $color !== self::DEFAULT_COLOR,
            'login_style' => in_array($style, self::LOGIN_STYLES, true) ? $style : 'dark',
            'css_url' => $cssOk ? SYSTEM_URL . $css : null,
        ];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /** A #rrggbb color (lowercase), or null. Accepts #rgb, and the hex digits without "#". */
    public static function validColor(string $color): ?string
    {
        $color = '#' . ltrim(strtolower(trim($color)), '#');
        if (preg_match('/\A#([0-9a-f])([0-9a-f])([0-9a-f])\z/', $color, $m) === 1) {
            $color = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return preg_match('/\A#[0-9a-f]{6}\z/', $color) === 1 ? $color : null;
    }

    /**
     * Saves the brand color and the sign-in style, and writes (or removes) the stylesheet.
     */
    public static function save(\Pimple\Container $di, string $color, string $loginStyle): array
    {
        $valid = self::validColor($color);
        if ($valid === null) {
            throw new InformationException('Choose a color such as #2672e3.');
        }
        if (!in_array($loginStyle, self::LOGIN_STYLES, true)) {
            throw new InformationException('Choose a sign-in page style.');
        }
        $system = $di['mod_service']('system');
        $system->setParamValue(self::COLOR, $valid);
        $system->setParamValue(self::LOGIN_STYLE, $loginStyle);

        $dir = self::dir();
        foreach (glob($dir . '/brand-*.css') ?: [] as $old) {
            @unlink($old);
        }
        if ($valid === self::DEFAULT_COLOR) {
            $system->setParamValue(self::CSS, '');
        } else {
            $css = self::css($valid);
            $name = 'brand-' . substr(hash('sha256', $css), 0, 12) . '.css';
            self::ensureDir();
            if (file_put_contents($dir . '/' . $name, $css) === false) {
                throw new InformationException('The brand stylesheet could not be written: check that :dir is writable.', [':dir' => self::DIR]);
            }
            $system->setParamValue(self::CSS, self::DIR . '/' . $name);
        }
        self::forget();
        $di['cache']->clear();

        return self::get($di);
    }

    // ----- colors ---------------------------------------------------------------------------

    /** @return array{int, int, int} */
    public static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** Mixes $hex with $with (share 0..1 of $with). */
    public static function mix(string $hex, string $with, float $share): string
    {
        $a = self::rgb($hex);
        $b = self::rgb($with);
        $out = '#';
        for ($i = 0; $i < 3; ++$i) {
            $out .= sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $share));
        }

        return $out;
    }

    /** Text color that reads on the brand color: white, or near black on light colors. */
    public static function textOn(string $hex): string
    {
        $lin = array_map(static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        $luminance = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];

        // White text needs a contrast of at least 4.5:1, i.e. luminance up to about 0.18.
        return $luminance > 0.18 ? '#111827' : '#ffffff';
    }

    /**
     * The colors of the email templates: the custom brand color with its text and link colors, or the emails' own
     * blue while the brand color is the default (GitHub #10).
     *
     * @return array{color: string, text: string, link: string}
     */
    public static function email(\Pimple\Container $di): array
    {
        $brand = self::get($di);
        if (!$brand['custom']) {
            return ['color' => '#2563eb', 'text' => '#ffffff', 'link' => '#2563eb'];
        }
        $text = self::textOn($brand['color']);

        // A light brand color cannot be read as link text on white: its deep shade is used instead.
        return ['color' => $brand['color'], 'text' => $text, 'link' => $text === '#ffffff' ? $brand['color'] : self::palette($brand['color'])['deep']];
    }

    /**
     * The shades made from one brand color.
     *
     * @return array<string, string>
     */
    public static function palette(string $hex): array
    {
        return [
            'brand' => $hex,
            'deep' => self::mix($hex, '#000000', 0.45),
            'accent' => self::mix($hex, '#ffffff', 0.3),
            'hover' => self::mix($hex, '#000000', 0.15),
            'active' => self::mix($hex, '#000000', 0.22),
            'text' => self::textOn($hex),
            'subtle' => self::mix($hex, '#ffffff', 0.85),
            'border_subtle' => self::mix($hex, '#ffffff', 0.6),
            'dark_subtle' => self::mix($hex, '#000000', 0.8),
            'dark_border' => self::mix($hex, '#000000', 0.6),
            'dark_link_hover' => self::mix(self::mix($hex, '#ffffff', 0.3), '#ffffff', 0.2),
        ];
    }

    /** The stylesheet for a brand color (both themes). */
    public static function css(string $hex): string
    {
        $p = self::palette($hex);
        $rgb = static fn (string $c): string => implode(', ', self::rgb($c));
        $r = $rgb($p['brand']);
        $deepRgb = $rgb($p['deep']);
        $accentRgb = $rgb($p['accent']);
        $hoverRgb = $rgb($p['hover']);
        $darkHoverRgb = $rgb($p['dark_link_hover']);

        return <<<CSS
            /* ClientFence brand color (Clientfence/Branding.php): written when the color is saved. */
            :root, [data-bs-theme="light"] {
              --pal-brand: {$p['brand']};
              --pal-brand-rgb: {$r};
              --pal-brand-deep: {$p['deep']};
              --pal-brand-deep-rgb: {$deepRgb};
              --pal-accent: {$p['accent']};
              --pal-accent-rgb: {$accentRgb};
              --bs-primary: {$p['brand']};
              --bs-primary-rgb: {$r};
              --bs-primary-text-emphasis: {$p['deep']};
              --bs-primary-bg-subtle: {$p['subtle']};
              --bs-primary-border-subtle: {$p['border_subtle']};
              --bs-link-color: {$p['brand']};
              --bs-link-color-rgb: {$r};
              --bs-link-hover-color: {$p['hover']};
              --bs-link-hover-color-rgb: {$hoverRgb};
              --bs-focus-ring-color: rgba({$r}, .25);
              --bf-brand: {$p['brand']};
              --bf-brand-hover: {$p['hover']};
              --tblr-primary: {$p['brand']};
              --tblr-primary-rgb: {$r};
              --tblr-primary-fg: {$p['text']};
            }
            [data-bs-theme="dark"] {
              --bs-primary-text-emphasis: {$p['accent']};
              --bs-primary-bg-subtle: {$p['dark_subtle']};
              --bs-primary-border-subtle: {$p['dark_border']};
              --bs-link-color: {$p['accent']};
              --bs-link-color-rgb: {$accentRgb};
              --bs-link-hover-color: {$p['dark_link_hover']};
              --bs-link-hover-color-rgb: {$darkHoverRgb};
              --bf-brand: {$p['accent']};
              --bf-brand-hover: {$p['dark_link_hover']};
            }
            .btn-primary {
              --bs-btn-color: {$p['text']};
              --bs-btn-bg: {$p['brand']};
              --bs-btn-border-color: {$p['brand']};
              --bs-btn-hover-color: {$p['text']};
              --bs-btn-hover-bg: {$p['hover']};
              --bs-btn-hover-border-color: {$p['hover']};
              --bs-btn-focus-shadow-rgb: {$r};
              --bs-btn-active-color: {$p['text']};
              --bs-btn-active-bg: {$p['active']};
              --bs-btn-active-border-color: {$p['active']};
              --bs-btn-disabled-color: {$p['text']};
              --bs-btn-disabled-bg: {$p['brand']};
              --bs-btn-disabled-border-color: {$p['brand']};
            }
            .btn-outline-primary {
              --bs-btn-color: {$p['brand']};
              --bs-btn-border-color: {$p['brand']};
              --bs-btn-hover-color: {$p['text']};
              --bs-btn-hover-bg: {$p['brand']};
              --bs-btn-hover-border-color: {$p['brand']};
              --bs-btn-focus-shadow-rgb: {$r};
              --bs-btn-active-color: {$p['text']};
              --bs-btn-active-bg: {$p['brand']};
              --bs-btn-active-border-color: {$p['brand']};
              --bs-btn-disabled-color: {$p['brand']};
              --bs-btn-disabled-border-color: {$p['brand']};
            }
            .form-check-input:checked { background-color: {$p['brand']}; border-color: {$p['brand']}; }
            .form-check-input:focus, .form-control:focus, .form-select:focus { border-color: {$p['accent']}; box-shadow: 0 0 0 .25rem rgba({$r}, .25); }
            .nav-pills { --bs-nav-pills-link-active-bg: {$p['brand']}; --bs-nav-pills-link-active-color: {$p['text']}; }
            .pagination { --bs-pagination-active-bg: {$p['brand']}; --bs-pagination-active-border-color: {$p['brand']}; --bs-pagination-active-color: {$p['text']}; }
            .dropdown-menu { --bs-dropdown-link-active-bg: {$p['brand']}; --bs-dropdown-link-active-color: {$p['text']}; }
            .progress, .progress-stacked { --bs-progress-bar-bg: {$p['brand']}; }
            .list-group { --bs-list-group-active-bg: {$p['brand']}; --bs-list-group-active-border-color: {$p['brand']}; --bs-list-group-active-color: {$p['text']}; }

            CSS;
    }

    // ----- uploads --------------------------------------------------------------------------

    /**
     * Saves an uploaded logo, dark logo or icon (`kind`): an image (PNG, JPEG, GIF or WebP where
     * the server reads it) of at most 2 MB, scaled down to fit, stored as PNG. Returns its URL.
     */
    public static function saveAsset(\Pimple\Container $di, string $kind, ?UploadedFile $file): string
    {
        if (!isset(self::ASSETS[$kind])) {
            throw new InformationException('Unknown image.');
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InformationException('Choose an image to upload.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new InformationException('The image is larger than 2 MB.');
        }
        $data = (string) file_get_contents($file->getPathname());
        $png = self::toPng($data, self::ASSETS[$kind]['width'], self::ASSETS[$kind]['height'], $kind === 'icon');

        self::ensureDir();
        $dir = self::dir();
        $name = str_replace('_', '-', $kind) . '-' . bin2hex(random_bytes(8)) . '.png';
        if (file_put_contents($dir . '/' . $name, $png) === false) {
            throw new InformationException('The image could not be saved: check that :dir is writable.', [':dir' => self::DIR]);
        }
        $param = self::ASSETS[$kind]['param'];
        $system = $di['mod_service']('system');
        $previous = (string) $system->getParamValue($param, '');
        $system->setParamValue($param, self::DIR . '/' . $name);
        // Only an earlier upload of this kind is removed, never the shipped default logo.
        $prefix = self::DIR . '/' . str_replace('_', '-', $kind) . '-';
        if (str_starts_with($previous, $prefix) && preg_match('#\A[a-z-]+-[a-f0-9]{16}\.png\z#', basename($previous)) === 1) {
            @unlink(PATH_ROOT . '/' . $previous);
        }
        $di['cache']->clear();

        return SYSTEM_URL . self::DIR . '/' . $name;
    }

    /**
     * Decodes an image and re-encodes it as PNG, scaled to fit width × height (never enlarged);
     * a square icon is centered on a transparent square canvas.
     */
    public static function toPng(string $data, int $maxWidth, int $maxHeight, bool $square): string
    {
        $info = @getimagesizefromstring($data);
        $types = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if ($info === false || !in_array($info[2], $types, true)) {
            throw new InformationException('Upload a PNG, JPEG, GIF or WebP image.');
        }
        [$width, $height] = $info;
        if ($width < 16 || $height < 16 || $width > 4000 || $height > 4000) {
            throw new InformationException('The image must be between 16 and 4000 pixels wide and high.');
        }
        $source = @imagecreatefromstring($data);
        if ($source === false) {
            throw new InformationException('This server cannot read that image type: upload a PNG or JPEG.');
        }
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvasW = $square ? max($w, $h) : $w;
        $canvasH = $square ? max($w, $h) : $h;
        $out = imagecreatetruecolor($canvasW, $canvasH);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagecopyresampled($out, $source, intdiv($canvasW - $w, 2), intdiv($canvasH - $h, 2), 0, 0, $w, $h, $width, $height);
        imagealphablending($out, false);
        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    private static function dir(): string
    {
        return PATH_ROOT . '/' . self::DIR;
    }

    /** The upload folder, with a guard that serves its files as plain files only. */
    private static function ensureDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new InformationException('The folder :dir could not be created: check that the site can write to public/.', [':dir' => self::DIR]);
        }
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "# ClientFence: brand images and the brand stylesheet only (Clientfence/Branding.php).\n"
                . "Options -ExecCGI -Indexes\n"
                . "RemoveHandler .php .phtml .phar\n"
                . "<FilesMatch \"\\.(?i:php\\d*|phtml|phar|shtml|cgi|pl|py)$\">\n"
                . "    Require all denied\n"
                . "</FilesMatch>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }
}
