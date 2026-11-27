<?php

namespace App\Services;

use App\DocumentClassification;

/**
 * Rule-based document classification (v2.0.1).
 *
 * No model, no outside call: each known type has a weighted
 * keyword list, and the score for a type is the matched weight
 * over the total weight of that type's list, counted over the
 * extracted text. A filename hint (bol, pod, invoice, rate,
 * detention) adds 0.10 to that type's score. The best type wins
 * only when its score reaches the threshold of 0.25; below
 * that, a document that still carries a date, an amount, or a
 * reference is "other", and one that carries none of those is
 * "unclassified". Nothing is forced into a type.
 *
 * Confidence is the winning score as a percentage, rounded to
 * two decimals. "other" is fixed at 50.00 — the neutral
 * midpoint for "a document, but not a known type" — and
 * "unclassified" is 0.00. The formula is stated here so the
 * numbers in the UI can be checked by hand.
 */
class DocumentClassifier
{
    /**
     * The score a type needs to win.
     */
    public const THRESHOLD = 0.25;

    /**
     * Confidence assigned to "other": a document, but not a
     * known type.
     */
    public const OTHER_CONFIDENCE = 50.00;

    /**
     * Weighted keywords per type: [phrase, weight].
     *
     * @var array<string, array<int, array{string, int}>>
     */
    protected const KEYWORDS = [
        'bill_of_lading' => [
            ['bill of lading', 3],
            ['bol', 2],
            ['shipper', 1],
            ['consignee', 1],
            ['pro number', 2],
            ['freight charge', 1],
            ['seal number', 1],
            ['trailer number', 1],
        ],
        'proof_of_delivery' => [
            ['proof of delivery', 3],
            ['pod', 2],
            ['delivered', 1],
            ['received by', 2],
            ['delivery date', 1],
            ['signed', 1],
        ],
        'invoice' => [
            ['invoice', 3],
            ['invoice number', 2],
            ['amount due', 2],
            ['payment terms', 1],
            ['remit to', 1],
            ['total due', 2],
            ['balance due', 1],
        ],
        'rate_confirmation' => [
            ['rate confirmation', 3],
            ['rate con', 2],
            ['agreed rate', 2],
            ['linehaul', 2],
            ['fuel surcharge', 1],
            ['load rate', 1],
        ],
        'detention_notice' => [
            ['detention', 3],
            ['detention charge', 2],
            ['detention hours', 2],
            ['detention fee', 2],
            ['layover', 2],
            ['waiting time', 1],
        ],
    ];

    /**
     * Filename tokens that hint at a type, one per type.
     *
     * @var array<string, string>
     */
    protected const FILENAME_HINTS = [
        'bill_of_lading' => 'bol',
        'proof_of_delivery' => 'pod',
        'invoice' => 'invoice',
        'rate_confirmation' => 'rate',
        'detention_notice' => 'detention',
    ];

    /**
     * Classify extracted text.
     *
     * @return array{classification: DocumentClassification, confidence: float, scores: array<string, float>}
     */
    public function classify(string $text, string $filename = ''): array
    {
        $scores = [];

        foreach (self::KEYWORDS as $type => $keywords) {
            $total = 0;
            $matched = 0;

            foreach ($keywords as [$phrase, $weight]) {
                $total += $weight;

                if ($this->containsPhrase($text, $phrase)) {
                    $matched += $weight;
                }
            }

            $score = $total > 0 ? $matched / $total : 0.0;

            $hint = self::FILENAME_HINTS[$type] ?? null;

            if ($hint !== null && $this->containsPhrase($filename, $hint)) {
                $score = min(1.0, $score + 0.10);
            }

            $scores[$type] = round($score, 4);
        }

        arsort($scores);

        $bestType = array_key_first($scores);
        $bestScore = $scores[$bestType] ?? 0.0;

        if ($bestScore >= self::THRESHOLD) {
            return [
                'classification' => DocumentClassification::from($bestType),
                'confidence' => round($bestScore * 100, 2),
                'scores' => $scores,
            ];
        }

        if ($this->looksLikeADocument($text)) {
            return [
                'classification' => DocumentClassification::Other,
                'confidence' => self::OTHER_CONFIDENCE,
                'scores' => $scores,
            ];
        }

        return [
            'classification' => DocumentClassification::Unclassified,
            'confidence' => 0.00,
            'scores' => $scores,
        ];
    }

    /**
     * Whether the text carries any document-like signal: a
     * date, a currency amount, or a reference-shaped token.
     * Used only to tell "other" (a document of an unknown
     * type) from "unclassified" (no usable content at all).
     */
    protected function looksLikeADocument(string $text): bool
    {
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $text) === 1) {
            return true;
        }

        if (preg_match('/\$\s?\d[\d,]*(?:\.\d{2})?/', $text) === 1) {
            return true;
        }

        if (preg_match('/\b[A-Z]{2,10}(?:[-_][A-Z0-9]{1,10}){1,3}\b/i', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Word-boundary phrase search, case-insensitive. Short
     * tokens like "bol" and "pod" must stand alone so "symbol"
     * never counts as a bill of lading.
     */
    protected function containsPhrase(string $haystack, string $phrase): bool
    {
        return preg_match('/\b'.preg_quote($phrase, '/').'\b/i', $haystack) === 1;
    }
}
