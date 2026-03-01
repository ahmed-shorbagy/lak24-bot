<?php
/**
 * FileProcessor — Handles PDF text extraction and image processing
 * 
 * Processes uploaded files (PDFs and images) for translation via GPT-4o-mini.
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/SimplePDFExtractor.php';

// Load Composer autoloader for smalot/pdfparser (professional PDF library)
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

class FileProcessor
{
    private array $uploadConfig;
    private ?Logger $logger;

    public function __construct(array $uploadConfig, ?Logger $logger = null)
    {
        $this->uploadConfig = $uploadConfig;
        $this->logger       = $logger;

        $storagePath = $uploadConfig['storage_path'] ?? __DIR__ . '/../uploads/';
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
    }

    /**
     * Process an uploaded file
     * 
     * @param array $file $_FILES array entry
     * @return array ['success' => bool, 'type' => string, 'data' => mixed, 'error' => string|null]
     */
    public function processUpload(array $file): array
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return $this->processPDF($file);
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            return $this->processImage($file);
        }

        return [
            'success' => false,
            'type'    => 'unknown',
            'data'    => null,
            'error'   => 'Unsupported file type: ' . $ext,
        ];
    }

    /**
     * Process a PDF file — extract text content across all pages
     */
    private function processPDF(array $file): array
    {
        $tmpPath = $file['tmp_name'];

        try {
            // 1. Try Node.js first (more powerful)
            $result = $this->extractPDFWithNode($tmpPath);

            // 2. Fallback: Try smalot/pdfparser (professional PHP library)
            if (!$result['success'] || empty(trim($result['text'] ?? ''))) {
                if ($this->logger) {
                    $this->logger->info('Node.js PDF extraction failed, trying smalot/pdfparser', [
                        'filename' => $file['name'],
                        'error'    => $result['error'] ?? 'Empty text',
                    ]);
                }
                
                $smalotResult = $this->extractPDFWithSmalot($tmpPath);
                
                if ($smalotResult['success'] && !empty(trim($smalotResult['text'] ?? ''))) {
                    $text = $smalotResult['text'];
                    $pageCount = $smalotResult['pageCount'];
                } else {
                    // 3. Last resort: SimplePDFExtractor
                    if ($this->logger) {
                        $this->logger->info('smalot/pdfparser failed too, trying SimplePDFExtractor', [
                            'error' => $smalotResult['error'] ?? 'Empty text',
                        ]);
                    }
                    
                    $fallback = SimplePDFExtractor::extract($tmpPath);
                    
                    if (empty(trim($fallback['text']))) {
                        return [
                            'success' => false,
                            'type'    => 'pdf',
                            'data'    => null,
                            'error'   => 'عذراً، لم نتمكن من استخراج نص من هذا الملف. يرجى التأكد من أن الملف يحتوي على نص قابل للقراءة.',
                        ];
                    }

                    $text = $fallback['text'];
                    $pageCount = $fallback['pageCount'];
                }
            } else {
                $text = $result['text'];
                $pageCount = $result['pageCount'] ?? 1;
            }

            // --- UTF-8 Safety Cleanup ---
            if (function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
            // Remove invalid UTF-8 sequences and non-printable control chars
            $text = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]|\xED[\xA0-\xBF].{3}/', '', $text);
            $text = trim($text);

            if ($this->logger) {
                $this->logger->info('PDF processed successfully', [
                    'filename'    => $file['name'],
                    'text_length' => mb_strlen($text),
                    'page_count'  => $pageCount,
                    'method'      => isset($smalotResult) && $smalotResult['success'] ? 'smalot_pdfparser' : (isset($fallback) ? 'php_simple' : 'node_js')
                ]);
            }

            return [
                'success'    => true,
                'type'       => 'text',
                'data'       => $text,
                'page_count' => $pageCount,
                'filename'   => $file['name'],
                'error'      => null,
            ];
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('PDF processing failed', [
                    'filename' => $file['name'],
                    'error'    => $e->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'type'    => 'pdf',
                'data'    => null,
                'error'   => 'Failed to process PDF: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Extract text from PDF using Node.js script
     */
    private function extractPDFWithNode(string $pdfPath): array
    {
        $scriptPath = __DIR__ . '/../bin/pdf-extract.js';
        
        if (!file_exists($scriptPath)) {
            return ['success' => false, 'error' => 'Extraction script missing'];
        }

        // Check if we installed the standalone local Node binary
        $localNode = __DIR__ . '/../bin/node';
        if (file_exists($localNode) && is_executable($localNode)) {
            $nodeCmd = escapeshellarg($localNode);
        } else {
            $nodeCmd = 'node'; // Fallback to system node (if available)
        }

        $command = sprintf('%s %s %s 2>&1',
            $nodeCmd,
            escapeshellarg($scriptPath),
            escapeshellarg($pdfPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $rawOutput = implode("\n", $output);

        if ($returnCode !== 0 || empty($rawOutput)) {
            return ['success' => false, 'error' => $rawOutput ?: 'No output from Node.js'];
        }

        $decoded = json_decode($rawOutput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If it's not JSON, it's likely an error message or garbled output
            return ['success' => false, 'error' => 'Invalid JSON output from Node.js: ' . substr($rawOutput, 0, 100)];
        }

        return array_merge(['success' => true], $decoded);
    }


    /**
     * Extract text from PDF using smalot/pdfparser (professional PHP library)
     * Handles CIDFont, ToUnicode CMaps, font subsetting, and all modern PDF encodings.
     */
    private function extractPDFWithSmalot(string $pdfPath): array
    {
        if (!class_exists('\Smalot\PdfParser\Parser')) {
            return ['success' => false, 'error' => 'smalot/pdfparser not installed', 'text' => '', 'pageCount' => 0];
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);

            $pages = $pdf->getPages();
            $pageCount = count($pages);
            $textParts = [];

            foreach ($pages as $i => $page) {
                $pageText = $page->getText();
                if (!empty(trim($pageText))) {
                    $textParts[] = "--- Page " . ($i + 1) . " ---\n" . trim($pageText);
                }
            }

            $text = implode("\n\n", $textParts);

            // UTF-8 safety cleanup
            if (function_exists('mb_convert_encoding')) {
                $text = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
            $text = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]/', '', $text);

            return [
                'success'   => !empty(trim($text)),
                'text'      => trim($text),
                'pageCount' => $pageCount ?: 1,
                'error'     => empty(trim($text)) ? 'No text extracted' : null,
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'text'      => '',
                'pageCount' => 0,
                'error'     => 'smalot/pdfparser error: ' . $e->getMessage(),
            ];
        }
    }


    /**
     * Process an image file — resize and encode for Vision API
     */
    private function processImage(array $file): array
    {
        $tmpPath = $file['tmp_name'];
        $maxDim  = $this->uploadConfig['max_image_dim'] ?? 2048;

        // If GD extension is not available, skip GD processing entirely
        if (!extension_loaded('gd')) {
            if ($this->logger) {
                $this->logger->info('GD extension not available, using raw fallback');
            }
            return $this->processImageRaw($file);
        }

        try {
            // Get image info
            $imageInfo = getimagesize($tmpPath);

            // If getimagesize fails, try reading raw bytes directly
            if ($imageInfo === false) {
                return $this->processImageRaw($file);
            }

            [$width, $height] = $imageInfo;
            $mime = $imageInfo['mime'];

            // Load image based on type
            $image = null;
            switch ($mime) {
                case 'image/jpeg':
                case 'image/pjpeg':
                    $image = @imagecreatefromjpeg($tmpPath);
                    break;
                case 'image/png':
                case 'image/x-png':
                    $image = @imagecreatefrompng($tmpPath);
                    break;
                case 'image/webp':
                    $image = @imagecreatefromwebp($tmpPath);
                    break;
                default:
                    // Unknown type — try raw fallback
                    return $this->processImageRaw($file);
            }

            if (!$image) {
                // GD failed — try raw fallback
                return $this->processImageRaw($file);
            }

            // Resize if necessary
            if ($width > $maxDim || $height > $maxDim) {
                $ratio     = min($maxDim / $width, $maxDim / $height);
                $newWidth  = (int) ($width * $ratio);
                $newHeight = (int) ($height * $ratio);

                $resized = imagecreatetruecolor($newWidth, $newHeight);

                // Preserve transparency for PNG
                if ($mime === 'image/png' || $mime === 'image/x-png') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                }

                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }

            // Convert to base64
            ob_start();
            switch ($mime) {
                case 'image/jpeg':
                case 'image/pjpeg':
                    imagejpeg($image, null, 85);
                    $mime = 'image/jpeg';
                    break;
                case 'image/png':
                case 'image/x-png':
                    imagepng($image, null, 6);
                    $mime = 'image/png';
                    break;
                case 'image/webp':
                    imagewebp($image, null, 85);
                    break;
            }
            $imageData = base64_encode(ob_get_clean());
            imagedestroy($image);

            if ($this->logger) {
                $this->logger->info('Image processed', [
                    'filename'  => $file['name'],
                    'original'  => "{$width}x{$height}",
                    'mime'      => $mime,
                ]);
            }

            return [
                'success'  => true,
                'type'     => 'image',
                'data'     => $imageData,
                'mime'     => $mime,
                'filename' => $file['name'],
                'error'    => null,
            ];
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Image processing failed, trying raw fallback', [
                    'filename' => $file['name'],
                    'error'    => $e->getMessage(),
                ]);
            }

            // Last resort: raw file bytes
            return $this->processImageRaw($file);
        }
    }

    /**
     * Fallback: send raw image bytes to Vision API
     */
    private function processImageRaw(array $file): array
    {
        $tmpPath = $file['tmp_name'];
        $rawData = file_get_contents($tmpPath);

        if ($rawData === false || strlen($rawData) === 0) {
            return [
                'success' => false,
                'type'    => 'image',
                'data'    => null,
                'error'   => 'فشل في قراءة الملف. يرجى المحاولة مرة أخرى.',
            ];
        }

        // Detect MIME from file content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        // Default to JPEG if detection fails
        if (empty($mime) || $mime === 'application/octet-stream') {
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $mime = $mimeMap[$ext] ?? 'image/jpeg';
        }

        if ($this->logger) {
            $this->logger->info('Image processed (raw fallback)', [
                'filename' => $file['name'],
                'mime'     => $mime,
                'size'     => strlen($rawData),
            ]);
        }

        return [
            'success'  => true,
            'type'     => 'image',
            'data'     => base64_encode($rawData),
            'mime'     => $mime,
            'filename' => $file['name'],
            'error'    => null,
        ];
    }

    /**
     * Clean up temporary files older than 1 hour
     */
    public function cleanup(): int
    {
        $deleted     = 0;
        $storagePath = $this->uploadConfig['storage_path'] ?? __DIR__ . '/../uploads/';

        if (!is_dir($storagePath)) {
            return 0;
        }

        $files = glob($storagePath . '*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file) && (time() - filemtime($file)) > 3600) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
