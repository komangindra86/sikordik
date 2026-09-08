<?php

declare(strict_types=1);

foreach ([192, 512] as $size) {
    $image = imagecreatetruecolor($size, $size);
    imageantialias($image, true);
    $brand = imagecolorallocate($image, 8, 117, 101);
    $white = imagecolorallocate($image, 255, 255, 255);
    $mint = imagecolorallocate($image, 213, 246, 238);
    imagefill($image, 0, 0, $brand);

    $center = intdiv($size, 2);
    $shieldWidth = (int) ($size * 0.54);
    $shieldTop = (int) ($size * 0.28);
    imagefilledellipse($image, $center, $shieldTop, $shieldWidth, (int) ($size * 0.24), $mint);
    imagefilledrectangle($image, (int) ($size * 0.23), (int) ($size * 0.36), (int) ($size * 0.77), (int) ($size * 0.70), $white);
    imagefilledellipse($image, $center, (int) ($size * 0.68), $shieldWidth, (int) ($size * 0.34), $white);

    $bar = (int) ($size * 0.11);
    $length = (int) ($size * 0.31);
    imagefilledrectangle($image, $center - intdiv($bar, 2), $center - intdiv($length, 2), $center + intdiv($bar, 2), $center + intdiv($length, 2), $brand);
    imagefilledrectangle($image, $center - intdiv($length, 2), $center - intdiv($bar, 2), $center + intdiv($length, 2), $center + intdiv($bar, 2), $brand);

    imagepng($image, dirname(__DIR__)."/public/icons/sikordik-{$size}.png", 9);
    imagedestroy($image);
}
