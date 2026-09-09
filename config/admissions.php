<?php

return [
    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
    'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),
];
