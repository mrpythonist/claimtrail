<?php

namespace App\Services;

/**
 * The local OCR provider (v2.0.1) — the mock provider.
 *
 * This is NOT real OCR. It reads text that a document
 * already carries: plain text and CSV files verbatim, and the
 * text layer of a PDF (Tj/TJ operators in its content
 * streams, including FlateDecode-compressed streams). It
 * makes no outside call and holds no API key.
 *
 * Scanned images and image-only PDFs have no text layer, so
 * this provider returns no text for them and says so: real
 * OCR on scanned images needs a cloud OCR provider, which
 * is a later swap behind the OcrProvider interface. The
 * classifier and field extractor never see invented text.
 */
class LocalOcrProvider implements OcrProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function extractText(string $contents, string $mimeType, string $filename): OcrExtraction
    {
        $mimeType = strtolower(trim($mimeType));

        if (in_array($mimeType, ['text/plain', 'text/csv', 'text/tab-separated-values'], true)) {
            $text = $this->cleanText($contents);

            return new OcrExtraction($text, true);
        }

        if ($mimeType === 'application/pdf') {
            $text = $this->extractPdfText($contents);

            if ($text !== '') {
                return new OcrExtraction($text, true);
            }

            return new OcrExtraction(
                '',
                false,
                'No text layer found in this PDF (scanned image or image-only PDF): the local provider cannot OCR scanned images. A cloud OCR provider is required.',
            );
        }

        if (str_starts_with($mimeType, 'image/')) {
            return new OcrExtraction(
                '',
                false,
                'Scanned image: the local provider cannot OCR images. A cloud OCR provider is required.',
            );
        }

        return new OcrExtraction(
            '',
            false,
            'Unsupported document type for the local provider: '.$mimeType.'.',
        );
    }

    /**
     * Pull the text layer out of a PDF: decompress each
     * content stream (FlateDecode when present) and collect
     * the literal strings shown by Tj and TJ operators.
     * Nothing here guesses at text: a stream that cannot be
     * read contributes nothing.
     */
    protected function extractPdfText(string $contents): string
    {
        $texts = [];

        if (! preg_match_all('/stream\r?\n(.*?)endstream/s', $contents, $streams)) {
            return '';
        }

        foreach ($streams[1] as $stream) {
            $stream = ltrim($stream, "\r\n");

            $decoded = @gzuncompress($stream);

            if ($decoded === false) {
                $decoded = $stream;
            }

            foreach ($this->pdfTextOperators($decoded) as $piece) {
                $texts[] = $piece;
            }
        }

        return $this->cleanText(implode("\n", $texts));
    }

    /**
     * Literal strings shown by Tj operators, plus the string
     * elements of TJ arrays, in order.
     *
     * @return array<int, string>
     */
    protected function pdfTextOperators(string $stream): array
    {
        $texts = [];

        if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)\s*Tj/', $stream, $matches)) {
            foreach ($matches[0] as $match) {
                $texts[] = $this->unescapePdfString($match);
            }
        }

        if (preg_match_all('/\[((?:[^\[\]]|\((?:[^()\\\\]|\\\\.)*\))*)\\]\s*TJ/', $stream, $arrays)) {
            foreach ($arrays[1] as $array) {
                if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $array, $parts)) {
                    foreach ($parts[0] as $part) {
                        $texts[] = $this->unescapePdfString($part);
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * One PDF literal string with its escapes resolved and
     * the outer parentheses removed.
     */
    protected function unescapePdfString(string $raw): string
    {
        $inner = preg_replace('/^\(|\)\s*Tj$/', '', $raw) ?? '';
        $inner = preg_replace('/\)$/', '', $inner) ?? '';

        return str_replace(
            ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
            ['(', ')', '\\', "\n", "\r", "\t"],
            $inner,
        );
    }

    /**
     * Normalize extracted text: unify line endings, collapse
     * runs of blank lines, trim. The text is stored as
     * extracted, not rewritten.
     */
    protected function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
