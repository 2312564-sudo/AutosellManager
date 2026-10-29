<?php
/* Phần HTML dùng chung: <head> + phông trời hoàng hôn có núi và rặng thông. */
declare(strict_types=1);

function qq_head(string $title): void
{
    $t = htmlspecialchars($title, ENT_QUOTES);
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="theme-color" content="#140c2c"><meta name="robots" content="noindex, nofollow">';
    echo '<link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icon-192.png"><link rel="apple-touch-icon" href="assets/icon-192.png">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="assets/app.css?v=' . QQ_VERSION . '"><title>' . $t . '</title></head><body>';
}

function qq_sky(): void
{
    $pines = '';
    $spots = [[40, 1000], [95, 1012], [150, 995], [260, 1020], [330, 1004], [420, 1016], [560, 1008], [640, 1022], [760, 1000], [880, 1014], [1010, 1006], [1150, 1018], [1290, 1002], [1380, 1012], [1420, 1010]];
    foreach ($spots as [$x, $b]) {
        $pines .= '<path d="M' . $x . ' ' . ($b - 70) . ' L' . ($x + 16) . ' ' . ($b - 30) . ' L' . ($x + 8) . ' ' . ($b - 30) . ' L' . ($x + 22) . ' ' . $b . ' L' . ($x - 22) . ' ' . $b . ' L' . ($x - 8) . ' ' . ($b - 30) . ' L' . ($x - 16) . ' ' . ($b - 30) . ' Z"/>';
    }
    $stars = '';
    mt_srand(7);
    for ($i = 0; $i < 70; $i++) {
        $stars .= '<circle cx="' . mt_rand(0, 1440) . '" cy="' . mt_rand(0, 420) . '" r="' . (mt_rand(0, 10) > 8 ? 1.6 : 1) . '" opacity="' . (mt_rand(25, 85) / 100) . '"/>';
    }
    echo <<<SVG
<div class="sky" aria-hidden="true"><svg viewBox="0 0 1440 1080" preserveAspectRatio="xMidYMax slice">
<defs><linearGradient id="dusk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#120a28"/><stop offset="0.42" stop-color="#341a57"/><stop offset="0.7" stop-color="#7d3668"/><stop offset="0.88" stop-color="#d86c5c"/><stop offset="1" stop-color="#f09a5c"/></linearGradient>
<radialGradient id="sun" cx="0.5" cy="0.5" r="0.5"><stop offset="0" stop-color="#ffd59a" stop-opacity="0.85"/><stop offset="1" stop-color="#ffd59a" stop-opacity="0"/></radialGradient></defs>
<rect width="1440" height="1080" fill="url(#dusk)"/><g fill="#fff">$stars</g>
<circle cx="1080" cy="880" r="260" fill="url(#sun)"/>
<path d="M0 760 L120 640 L210 700 L340 560 L470 690 L560 620 L700 720 L820 580 L960 700 L1080 610 L1210 690 L1330 590 L1440 660 L1440 1080 L0 1080 Z" fill="#5a2a63" opacity="0.75"/>
<path d="M0 860 L150 720 L260 800 L400 680 L540 820 L660 740 L800 840 L930 720 L1060 830 L1200 740 L1320 820 L1440 760 L1440 1080 L0 1080 Z" fill="#371a4a"/>
<path d="M0 960 L180 850 L320 930 L470 840 L620 950 L780 870 L940 960 L1100 880 L1260 950 L1440 890 L1440 1080 L0 1080 Z" fill="#1d0f2e"/>
<g fill="#140a22">$pines</g></svg></div>
SVG;
}
