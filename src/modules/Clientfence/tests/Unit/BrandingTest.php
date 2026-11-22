<?php

declare(strict_types=1);

/**
 * ClientFence branding (Branding.php): brand colors, the generated stylesheet and uploaded images.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Api\Admin;
use ClientFence\Mod\Clientfence\Branding;
use Symfony\Component\HttpFoundation\File\UploadedFile;

function brandingTestImage(int $width, int $height, string $type = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 232, 93, 4));
    ob_start();
    $type === 'jpeg' ? imagejpeg($image) : imagepng($image);

    return (string) ob_get_clean();
}

test('brand colors are #rrggbb in lowercase', function (): void {
    expect(Branding::validColor('#2672E3'))->toBe('#2672e3')
        ->and(Branding::validColor(' #abc '))->toBe('#aabbcc')
        ->and(Branding::validColor('2672e3'))->toBe('#2672e3')
        ->and(Branding::validColor('##2672e3'))->toBe('#2672e3')
        ->and(Branding::validColor('#2672e'))->toBeNull()
        ->and(Branding::validColor('#12345g'))->toBeNull()
        ->and(Branding::validColor('red'))->toBeNull()
        ->and(Branding::validColor('#fff;}body{'))->toBeNull();
});

test('button text is white on dark colors and near black on light ones', function (): void {
    expect(Branding::textOn('#2672e3'))->toBe('#ffffff')
        ->and(Branding::textOn('#000000'))->toBe('#ffffff')
        ->and(Branding::textOn('#ffff00'))->toBe('#111827')
        ->and(Branding::textOn('#e85d04'))->toBe('#111827');
});

test('shades are mixed with black or white', function (): void {
    expect(Branding::mix('#000000', '#ffffff', 0.5))->toBe('#808080')
        ->and(Branding::mix('#2672e3', '#000000', 0.0))->toBe('#2672e3')
        ->and(Branding::mix('#2672e3', '#ffffff', 1.0))->toBe('#ffffff')
        ->and(Branding::palette('#2672e3'))->toHaveKeys(['brand', 'deep', 'accent', 'hover', 'active', 'text', 'subtle']);
});

test('the stylesheet sets the client and admin color variables for both color modes', function (): void {
    $css = Branding::css('#e85d04');

    expect($css)->toContain('--pal-brand: #e85d04')
        ->toContain('--pal-brand-rgb: 232, 93, 4')
        ->toContain('--bs-primary: #e85d04')
        ->toContain('--tblr-primary: #e85d04')
        ->toContain('--bf-brand: #e85d04')
        ->toContain('[data-bs-theme="dark"]')
        ->toContain('.btn-primary')
        // Outline buttons in dark mode use the lighter accent (the brand color is too dark on the navy page).
        ->toContain("[data-bs-theme=\"dark\"] .btn-outline-primary {\n  --bs-btn-color: " . Branding::palette('#e85d04')['accent'])
        ->not->toContain('url(')
        ->not->toContain('@import');
});

test('uploaded images are scaled down and saved as PNG', function (): void {
    $png = Branding::toPng(brandingTestImage(2400, 400), 1200, 400, false);
    $info = getimagesizefromstring($png);
    expect($info[2])->toBe(IMAGETYPE_PNG)
        ->and([$info[0], $info[1]])->toBe([1200, 200]);

    // Small images are never enlarged; a JPEG becomes a PNG too.
    $info = getimagesizefromstring(Branding::toPng(brandingTestImage(300, 100, 'jpeg'), 1200, 400, false));
    expect([$info[0], $info[1], $info[2]])->toBe([300, 100, IMAGETYPE_PNG]);

    // The icon is centered on a square canvas.
    $info = getimagesizefromstring(Branding::toPng(brandingTestImage(300, 100), 512, 512, true));
    expect([$info[0], $info[1]])->toBe([300, 300]);
});

test('files that are not images, or too small or too large, are refused', function (): void {
    expect(fn () => Branding::toPng('<?php echo 1;', 1200, 400, false))->toThrow(InformationException::class)
        ->and(fn () => Branding::toPng('<svg xmlns="http://www.w3.org/2000/svg"/>', 1200, 400, false))->toThrow(InformationException::class)
        ->and(fn () => Branding::toPng(brandingTestImage(10, 10), 1200, 400, false))->toThrow(InformationException::class)
        ->and(fn () => Branding::toPng(brandingTestImage(4100, 20), 1200, 400, false))->toThrow(InformationException::class);
});

test('an unknown image kind or a missing file is refused before anything is written', function (): void {
    $di = new Pimple\Container();

    expect(fn () => Branding::saveAsset($di, 'background', null))->toThrow(InformationException::class)
        ->and(fn () => Branding::saveAsset($di, 'logo', null))->toThrow(InformationException::class);
});

function brandingTestUpload(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'brand-');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'image.png', 'image/png', null, true);
}

test('Branding saves SVG by content, strips unsafe elements, attributes and references, and sizes a viewBox-only logo', function (string $kind, string $param): void {
    ob_start();
    imagepng(imagecreatetruecolor(16, 16));
    $png = base64_encode((string) ob_get_clean());
    // An SVG hidden in a "PNG": the PDF renderer would draw it and follow its local file reference.
    $hidden = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="public/branding/logo.png"/></svg><!--/a.svg');
    $file = brandingTestUpload(<<<SVG
        <?xml version="1.0"?>
        <!-- Exported logo -->
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 40">
            <script>alert(1)</script>
            <rect width="100" height="40" onload="alert(1)"/>
            <a href="javascript:alert(1)"><circle cx="20" cy="20" r="10"/></a>
            <use href="https://example.com/logo.svg#shape"/>
            <image xlink:href="https://example.com/image.png" width="20" height="20"/>
            <style>@import url(https://example.com/x.css); .a { fill: url(https://example.com/f.svg#g) }</style>
            <image href="data:image/png;base64,{$png}" width="20" height="20"/>
            <image href="data:image/png;base64,{$hidden}" width="20" height="20"/>
        </svg>
        SVG);
    $di = new Pimple\Container();
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->once()->with($param, '')->andReturn('');
    $system->shouldReceive('setParamValue')->once()->with($param, Mockery::capture($saved));
    $di['mod_service'] = $di->protect(fn () => $system);
    $di['cache'] = Mockery::mock()->expects('clear')->getMock();

    try {
        $url = Branding::saveAsset($di, $kind, $file);
        expect($saved)->toMatch('/\Apublic\/uploads\/brand\/' . str_replace('_', '-', $kind) . '-[a-f0-9]{16}\.svg\z/')
            ->and($url)->toBe(SYSTEM_URL . $saved);
        expect(file_get_contents(PATH_ROOT . '/' . $saved))
            ->toContain('<rect')
            ->toContain('width="100" height="40"')
            ->toContain('href="data:image/png;base64,iVBOR')
            ->not->toContain($hidden)
            ->toContain('.a { fill: none }')
            ->not->toMatch('~<script|onload=|javascript:|https://example\.com/~');
    } finally {
        unlink($file->getPathname());
        if (isset($saved)) {
            unlink(PATH_ROOT . '/' . $saved);
        }
    }
})->with([
    'light logo' => ['logo', 'company_logo'],
    'dark logo' => ['logo_dark', 'company_logo_dark'],
]);

test('Branding refuses files without usable SVG artwork', function (string $content): void {
    $file = brandingTestUpload($content);
    try {
        expect(fn () => Branding::saveAsset(new Pimple\Container(), 'logo', $file))->toThrow(InformationException::class);
    } finally {
        unlink($file->getPathname());
    }
})->with([
    'plain text' => ['this is not an image'],
    'other XML root' => ['<g xmlns="http://www.w3.org/2000/svg"><rect width="20" height="20"/></g>'],
    'malformed SVG' => ['<svg xmlns="http://www.w3.org/2000/svg">'],
    'empty after sanitising' => ['<svg xmlns="http://www.w3.org/2000/svg"><g><script>alert(1)</script></g></svg>'],
]);

test('Branding refuses SVG for the email logo and square icon', function (string $kind): void {
    $file = brandingTestUpload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect width="20" height="20"/></svg>');
    try {
        expect(fn () => Branding::saveAsset(new Pimple\Container(), $kind, $file))->toThrow(InformationException::class);
    } finally {
        unlink($file->getPathname());
    }
})->with(['logo_email', 'icon']);

test('Branding restore saves the shipped default and deletes only an earlier upload of that kind', function (string $kind, string $param, string $default, string $extension, bool $managed): void {
    $directory = PATH_ROOT . '/' . Branding::DIR;
    if (!is_dir($directory)) {
        mkdir($directory, 0o755, true);
    }
    $prefix = str_replace('_', '-', $kind) . ($managed ? '' : '-other');
    $previous = Branding::DIR . '/' . $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    file_put_contents(PATH_ROOT . '/' . $previous, 'previous image');
    $di = new Pimple\Container();
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->once()->with($param, '')->andReturn($previous);
    $system->shouldReceive('setParamValue')->once()->with($param, $default);
    $di['mod_service'] = $di->protect(fn () => $system);
    $di['cache'] = Mockery::mock()->expects('clear')->getMock();

    try {
        $url = Branding::restoreAsset($di, $kind);
        expect($url)->toBe($default === '' ? null : SYSTEM_URL . $default)
            ->and(is_file(PATH_ROOT . '/' . $previous))->toBe(!$managed);
    } finally {
        if (is_file(PATH_ROOT . '/' . $previous)) {
            unlink(PATH_ROOT . '/' . $previous);
        }
    }
})->with([
    'light logo SVG' => ['logo', 'company_logo', 'public/branding/logo.png', 'svg', true],
    'dark logo PNG' => ['logo_dark', 'company_logo_dark', 'public/branding/logo-dark.png', 'png', true],
    'email logo' => ['logo_email', 'company_logo_email', '', 'png', true],
    'square icon' => ['icon', 'company_favicon', 'public/branding/favicon.ico', 'png', true],
    'similar filename' => ['logo', 'company_logo', 'public/branding/logo.png', 'png', false],
]);

test('Branding restore refuses an unknown image kind', function (): void {
    expect(fn () => Branding::restoreAsset(new Pimple\Container(), 'background'))->toThrow(InformationException::class, 'Unknown image.');
});

test('Branding restore API requires a Super Administrator', function (): void {
    $di = new Pimple\Container(['loggedin_admin' => (object) ['id' => 2]]);
    $staff = Mockery::mock();
    $staff->shouldReceive('isSuperAdministrator')->once()->with(2)->andReturnFalse();
    $di['mod_service'] = $di->protect(fn (string $name) => ['staff' => $staff][$name]);
    $api = new Admin();
    $api->setDi($di);

    expect(fn () => $api->setup_brand_asset_restore(['kind' => 'logo']))->toThrow(function (InformationException $exception): void {
        expect($exception->getCode())->toBe(403);
    });
});

test('a color or sign-in style that is not offered is refused', function (): void {
    $di = new Pimple\Container();

    expect(fn () => Branding::save($di, 'blue', 'dark'))->toThrow(InformationException::class)
        ->and(fn () => Branding::save($di, '#2672e3', 'neon'))->toThrow(InformationException::class);
});

test('emails take the theme color by default and a custom one, with readable text and links (GitHub #10)', function (?string $color, array $expected): void {
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllKeyValue')->andReturn($color === null ? [] : [Branding::COLOR => $color]);
    $em = Mockery::mock();
    $em->shouldReceive('getConnection')->andReturn($connection);
    Branding::forget();

    expect(Branding::email(new Pimple\Container(['em' => $em])))->toBe($expected);
})->with([
    'no color set' => [null, ['color' => '#0e5a7a', 'text' => '#ffffff', 'link' => '#0e5a7a']],
    'the default color' => ['#0e5a7a', ['color' => '#0e5a7a', 'text' => '#ffffff', 'link' => '#0e5a7a']],
    'the default before 1.0.56, saved without a stylesheet' => ['#2672e3', ['color' => '#0e5a7a', 'text' => '#ffffff', 'link' => '#0e5a7a']],
    'a dark color' => ['#7c2d12', ['color' => '#7c2d12', 'text' => '#ffffff', 'link' => '#7c2d12']],
    'a light color' => ['#facc15', ['color' => '#facc15', 'text' => '#111827', 'link' => Branding::mix('#facc15', '#000000', 0.45)]],
]);
