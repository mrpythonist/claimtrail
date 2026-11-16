<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evidence storage disk
    |--------------------------------------------------------------------------
    |
    | Files are stored on a private disk and served only through the
    | authorized download route. Local development uses the "private"
    | disk under storage/app/private. Production sets EVIDENCE_DISK=s3
    | with the AWS credentials in the s3 disk configuration; downloads
    | then redirect to a short-lived signed URL instead of streaming.
    |
    */

    'disk' => env('EVIDENCE_DISK', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | Maximum file size in kilobytes, and the only file extensions the
    | upload endpoint accepts. The mimes rule checks the file's actual
    | contents against the MIME types for these extensions, so a
    | renamed executable does not pass as a PDF.
    |
    */

    'max_size_kb' => 20480,

    'allowed_extensions' => [
        'pdf', 'jpg', 'jpeg', 'png', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt',
    ],

];
