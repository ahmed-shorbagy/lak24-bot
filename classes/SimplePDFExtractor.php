<?php
/**
 * SimplePDFExtractor — A lightweight, pure-PHP PDF text extractor
 * 
 * This is a fallback for environments where Node.js/pdf-parse is unavailable.
 * It parses basic PDF text streams and handles common encodings.
 */

class SimplePDFExtractor
{
    /**
     * Extracts text from a PDF file
     * 
     * @param string $path Absolute path to the PDF
     * @return array ['text' => string, 'pageCount' => int]
     */
    public static function extract(string $path): array
    {
        if (!file_exists($path)) {
            return ['text' => '', 'pageCount' => 0];
        }

        $content = file_get_contents($path);
        if (!$content) {
            return ['text' => '', 'pageCount' => 0];
        }

        // 1. Estimate page count
        $pageCount = preg_match_all('/\/Page\b/', $content, $matches);
        if ($pageCount === 0) $pageCount = 1;

        // 2. Extract text from streams
        // We look for content streams (typically between BT and ET tags)
        // This is a simplified regex-based approach for shared hosting
        $text = "";
        
        // Find all objects containing text streams
        preg_match_all('/stream(.*?)endstream/ms', $content, $streams);
        
        foreach ($streams[1] as $stream) {
            $uncompressed = $stream;
            
            // Try to decompress if it looks like FlateDecode
            if (function_exists('gzuncompress')) {
                // Heuristic for FlateDecode start
                $pos = strpos($stream, "x\x9c");
                if ($pos !== false) {
                    $compressed = substr($stream, $pos);
                    $decompressed = @gzuncompress($compressed);
                    if ($decompressed) {
                        $uncompressed = $decompressed;
                    }
                }
            }

            // Extract text between BT and ET
            if (preg_match_all('/BT(.*?)ET/ms', $uncompressed, $textBlocks)) {
                foreach ($textBlocks[1] as $block) {
                    // Handle TJ (Arrays) and Tj (Strings)
                    
                    // 1. Extract from brackets [(...)] TJ
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjMatches)) {
                        foreach ($tjMatches[1] as $tj) {
                            preg_match_all('/\((.*?)(?<!\\\\)\)|<(.*?)>/s', $tj, $parts);
                            foreach ($parts[0] as $part) {
                                if ($part[0] === '(') {
                                    $text .= self::decodeString(substr($part, 1, -1));
                                } else {
                                    $text .= self::decodeHex(substr($part, 1, -1));
                                }
                            }
                            $text .= " ";
                        }
                    }

                    // 2. Extract single strings (String) Tj
                    preg_match_all('/\((.*?)(?<!\\\\)\)\s*Tj/s', $block, $tjMatches);
                    foreach ($tjMatches[1] as $match) {
                        $text .= self::decodeString($match) . " ";
                    }

                    // 3. Extract single hex strings <Hex> Tj
                    preg_match_all('/<(.*?)>\s*Tj/s', $block, $hexMatches);
                    foreach ($hexMatches[1] as $hex) {
                        $text .= self::decodeHex($hex) . " ";
                    }
                }
            }
        }

        // --- God-Mode Post-Processing: Arabic Refinement & Cleanup ---
        
        // 1. Force UTF-8 and strip any invalid bytes
        if (function_exists('mb_convert_encoding')) {
            $text = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // 2. Normalize whitespace and basic cleanup
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text); 
        
        // 3. Visual-to-Logical Correction (Fix backwards Arabic)
        $text = self::fixArabicOrder($text);

        // 4. Final Filter: Keep human characters (Arabic, Latin, Digits, Punctuation)
        $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}\p{P}\s]/u', ' ', $text);
        
        // 5. Decode HTML and normalize whitespace
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text); 

        return [
            'text' => trim($text),
            'pageCount' => $pageCount
        ];
    }

    /**
     * Helper to decode PDF Hex strings (handles standard and UTF-16BE)
     */
    private static function decodeHex(string $hex): string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if (empty($hex)) return "";
        
        // Arabic/Multibyte PDFs often use 4-digit hex (UTF-16BE)
        if (strlen($hex) >= 4) {
            $decoded = "";
            for ($i = 0; $i < strlen($hex); $i += 4) {
                $pair = substr($hex, $i, 4);
                if (strlen($pair) === 4) {
                    $char = @mb_convert_encoding(pack('H*', $pair), 'UTF-8', 'UTF-16BE');
                    if ($char) $decoded .= $char;
                }
            }
            if ($decoded) return $decoded;
        }

        $decoded = @hex2bin($hex);
        return $decoded ?: "";
    }

    /**
     * Decodes PDF string escapes
     */
    private static function decodeString(string $match): string
    {
        $replacements = [
            '\\\\' => '\\',
            '\\('  => '(',
            '\\)'  => ')',
            '\\n'  => "\n",
            '\\r'  => "\r",
            '\\t'  => "\t",
            '\\b'  => "\x08",
            '\\f'  => "\x0C",
        ];
        $text = str_replace(array_keys($replacements), array_values($replacements), $match);
        // Handle octal escapes \000
        $text = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $text);
        return $text;
    }

    /**
     * Corrects visual Arabic order (many PDFs store Arabic backward)
     */
    private static function fixArabicOrder(string $text): string
    {
        $words = explode(' ', $text);
        foreach ($words as &$word) {
            // If word is mostly Arabic characters, it might be reversed
            preg_match_all('/\p{Arabic}/u', $word, $matches);
            if (count($matches[0]) > (mb_strlen($word) / 2)) {
                // Heuristic: Check if the word is logically reversed
                // For simplicity, we reverse it and let AI handle the semantics
                // but only if it looks like a long string of Arabic
                if (mb_strlen($word) > 2) {
                    $word = self::mb_strrev($word);
                }
            }
        }
        return implode(' ', $words);
    }

    private static function mb_strrev($str) {
        $r = '';
        for ($i = mb_strlen($str); $i>=0; $i--) {
            $r .= mb_substr($str, $i, 1);
        }
        return $r;
    }
}
