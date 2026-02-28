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
                    // Format: [(String) -20 (Another) 50] TJ  or (String) Tj
                    
                    // 1. Extract from brackets [(...)] TJ
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjMatches)) {
                        foreach ($tjMatches[1] as $tj) {
                            // Extract strings within the array
                            preg_match_all('/\((.*?)\)|<(.*?)>/s', $tj, $parts);
                            foreach ($parts[0] as $part) {
                                if ($part[0] === '(') {
                                    $content = substr($part, 1, -1);
                                    $text .= str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $content);
                                } else {
                                    $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($part, 1, -1));
                                    $text .= self::decodeHex($hex);
                                }
                            }
                            $text .= " ";
                        }
                    }

                    // 2. Extract single strings (String) Tj
                    preg_match_all('/\((.*?)\)\s*Tj/s', $block, $tjMatches);
                    foreach ($tjMatches[1] as $match) {
                        $text .= str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $match) . " ";
                    }

                    // 3. Extract single hex strings <Hex> Tj
                    preg_match_all('/<(.*?)>\s*Tj/s', $block, $hexMatches);
                    foreach ($hexMatches[1] as $hex) {
                        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
                        $text .= self::decodeHex($hex) . " ";
                    }
                }
            }
        }

        // --- Hyper-Robust UTF-8 Safety & Cleanup ---
        
        // 1. Force UTF-8 and strip any invalid bytes immediately
        if (function_exists('mb_convert_encoding')) {
            $text = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // 2. Remove standard PDF escape sequences and control chars
        $text = preg_replace('/(\\\\[0-7]{3})/', ' ', $text); // Octal
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text); // Controls
        
        // 3. Final Filter: Keep ONLY human characters (Arabic, Latin, Digits, Space, Punctuation)
        // This is the most important step to prevent AI from seeing "corrupted" text.
        $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}\p{P}\s]/u', ' ', $text);
        
        // 4. Decode HTML and normalize whitespace
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
        if (empty($hex)) return "";
        
        // Arabic/Multibyte PDFs often use 4-digit hex (UTF-16BE)
        if (strlen($hex) >= 4) {
            $decoded = "";
            for ($i = 0; $i < strlen($hex); $i += 4) {
                $pair = substr($hex, $i, 4);
                if (strlen($pair) === 4) {
                    $decoded .= mb_convert_encoding(pack('H*', $pair), 'UTF-8', 'UTF-16BE');
                }
            }
            return $decoded;
        }

        $decoded = @hex2bin($hex);
        return $decoded ?: "";
    }
}
