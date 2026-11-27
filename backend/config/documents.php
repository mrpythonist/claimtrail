<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OCR provider
    |--------------------------------------------------------------------------
    |
    | The OCR provider behind the OcrProvider interface. Only "local"
    | is implemented in v2.0.1: it reads text a document already
    | carries and cannot OCR scanned images. A cloud OCR provider
    | ("cloud") is the swap-out target and is rejected until an
    | implementation lands, so a misconfigured driver fails loudly
    | instead of silently returning no text.
    |
    */

    'ocr_provider' => env('OCR_PROVIDER', 'local'),

];
