<?php

namespace App\Services;

/**
 * What an OCR provider returns for one document (v2.0.1).
 *
 * text is the extracted text, empty when nothing could be
 * read. has_text_layer is true when the document itself
 * carried text (a text file, or a PDF with a text layer).
 * Scanned images have no text layer, and the local provider
 * cannot read them: note says so plainly in that case.
 */
final class OcrExtraction
{
    public function __construct(
        public readonly string $text,
        public readonly bool $hasTextLayer,
        public readonly ?string $note = null,
    ) {}
}
