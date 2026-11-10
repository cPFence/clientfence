<?php

declare(strict_types=1);

/**
 * ClientFence branding (Branding.php): brand colors, the generated stylesheet and uploaded images.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Branding;

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

test('a color or sign-in style that is not offered is refused', function (): void {
    $di = new Pimple\Container();

    expect(fn () => Branding::save($di, 'blue', 'dark'))->toThrow(InformationException::class)
        ->and(fn () => Branding::save($di, '#2672e3', 'neon'))->toThrow(InformationException::class);
});

test('emails keep their blue with the default color and take a custom one, with readable text and links (GitHub #10)', function (?string $color, array $expected): void {
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllKeyValue')->andReturn($color === null ? [] : [Branding::COLOR => $color]);
    $em = Mockery::mock();
    $em->shouldReceive('getConnection')->andReturn($connection);
    Branding::forget();

    expect(Branding::email(new Pimple\Container(['em' => $em])))->toBe($expected);
})->with([
    'no color set' => [null, ['color' => '#2563eb', 'text' => '#ffffff', 'link' => '#2563eb']],
    'the default color' => ['#2672e3', ['color' => '#2563eb', 'text' => '#ffffff', 'link' => '#2563eb']],
    'a dark color' => ['#7c2d12', ['color' => '#7c2d12', 'text' => '#ffffff', 'link' => '#7c2d12']],
    'a light color' => ['#facc15', ['color' => '#facc15', 'text' => '#111827', 'link' => Branding::mix('#facc15', '#000000', 0.45)]],
]);
