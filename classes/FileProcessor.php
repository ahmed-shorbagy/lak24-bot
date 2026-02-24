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
     * Process a PDF file — extract text content
     */
    private function processPDF(array $file): array
    {
        $tmpPath = $file['tmp_name'];
        $text    = '';

        try {
            // Method 1: Try pdftotext (requires poppler-utils)
            $text = $this->extractPDFWithPdftotext($tmpPath);

            // Method 2: Basic PHP PDF text extraction
            if (empty(trim($text))) {
                $text = $this->extractPDFBasic($tmpPath);
            }

            // Method 3: If text extraction fails, convert PDF page to image
            // and use vision API instead
            if (empty(trim($text))) {
                // Try ImageMagick/Ghostscript first
                $imageData = $this->convertPDFToImage($tmpPath);
                if ($imageData) {
                    return [
                        'success'  => true,
                        'type'     => 'image',
                        'data'     => $imageData,
                        'mime'     => 'image/png',
                        'filename' => $file['name'],
                        'error'    => null,
                    ];
                }

                // Error: We couldn't parse it in PHP, and we have no Ghostscript to convert it to image.
                // We cannot send raw PDFs to OpenAI Vision API (it only accepts JPEG/PNG/WEBP).
                if ($this->logger) {
                    $this->logger->warning('PDF fallback: Server lacks pdftotext/Ghostscript to process this PDF', [
                        'filename' => $file['name'],
                    ]);
                }
                
                return [
                    'success' => false,
                    'type'    => 'pdf',
                    'data'    => null,
                    'error'   => 'عذراً، الخادم الحالي لا يدعم قراءة هذا النوع من ملفات PDF المعقدة. يرجى أخذ لقطة شاشة (Screenshot) للنص ورفعها كصورة بدلاً من ذلك.',
                ];
            }

            if ($this->logger) {
                $this->logger->info('PDF processed', [
                    'filename'    => $file['name'],
                    'text_length' => mb_strlen($text),
                ]);
            }

            return [
                'success'  => true,
                'type'     => 'text',
                'data'     => $text,
                'filename' => $file['name'],
                'error'    => null,
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
     * Extract text from PDF using pdftotext command-line tool
     */
    private function extractPDFWithPdftotext(string $pdfPath): string
    {
        // Check if pdftotext is available
        $check = shell_exec('where pdftotext 2>NUL') ?: shell_exec('which pdftotext 2>/dev/null');
        if (empty($check)) {
            return '';
        }

        $outputFile = tempnam(sys_get_temp_dir(), 'pdf_');
        $command    = sprintf('pdftotext -enc UTF-8 %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputFile)) {
            $text = file_get_contents($outputFile);
            unlink($outputFile);
            return $text;
        }

        if (file_exists($outputFile)) {
            unlink($outputFile);
        }

        return '';
    }

    /**
     * Basic PHP PDF text extraction (handles simple PDFs)
     */
    private function extractPDFBasic(string $pdfPath): string
    {
        $content = file_get_contents($pdfPath);

        if ($content === false) {
            return '';
        }

        $text = '';

        // Try to extract text from PDF streams
        // This handles uncompressed text streams
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                // Extract text from Tj and TJ operators
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $match, $textMatches)) {
                    $text .= implode(' ', $textMatches[1]) . "\n";
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $match, $textMatches)) {
                    foreach ($textMatches[1] as $tj) {
                        if (preg_match_all('/\((.*?)\)/s', $tj, $innerMatches)) {
                            $text .= implode('', $innerMatches[1]);
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        // Try deflate compressed streams
        if (empty(trim($text)) && preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streamMatches)) {
            foreach ($streamMatches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded !== false) {
                    // Extract text operators from decoded stream
                    if (preg_match_all('/\((.*?)\)\s*Tj/s', $decoded, $textMatches)) {
                        $text .= implode(' ', $textMatches[1]) . "\n";
                    }
                }
            }
        }

        // Clean up extracted text
        $text = preg_replace('/[^\P{C}\n\t ]/u', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Convert first page of PDF to image (for vision API)
     * Requires ImageMagick or Ghostscript
     */
    private function convertPDFToImage(string $pdfPath): ?string
    {
        // Try ImageMagick
        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick();
                $imagick->setResolution(200, 200);
                $imagick->readImage($pdfPath . '[0]'); // First page only
                $imagick->setImageFormat('png');

                $imageData = base64_encode($imagick->getImageBlob());
                $imagick->clear();
                $imagick->destroy();

                return $imageData;
            } catch (\Exception $e) {
                // Fall through to ghostscript
            }
        }

        // Try Ghostscript (command-line)
        $outputFile = tempnam(sys_get_temp_dir(), 'pdf_img_') . '.png';
        $gsCommands = ['gswin64c', 'gswin32c', 'gs'];

        foreach ($gsCommands as $gs) {
            $command = sprintf(
                '%s -dNOPAUSE -dBATCH -sDEVICE=png16m -r200 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>&1',
                $gs,
                escapeshellarg($outputFile),
                escapeshellarg($pdfPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 0) {
                $imageData = base64_encode(file_get_contents($outputFile));
                unlink($outputFile);
                return $imageData;
            }
        }

        if (file_exists($outputFile)) {
            unlink($outputFile);
        }

        return null;
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
