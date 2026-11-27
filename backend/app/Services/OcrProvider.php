<?php

namespace App\Services;

/**
 * The OCR provider contract (v2.0.1).
 *
 * The only provider in this version is the local one, which
 * reads text from text-based documents and never calls an
 * outside service. A real cloud OCR provider is a later swap
 * behind this same interface: it takes the same inputs and
 * returns the same shape, and nothing else in the app knows
 * which provider ran.
 */
interface OcrProvider
{
    /**
     * The provider key recorded on the extraction, for
     * example 'local'.
     */
    public function name(): string;

    /**
     * Extract the text of one document from its raw contents.
     *
     * @param  string  $contents  the file bytes as read from storage
     */
    public function extractText(string $contents, string $mimeType, string $filename): OcrExtraction;
}
