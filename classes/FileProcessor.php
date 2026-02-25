<?php
/**
 * FileProcessor — Upload handler + PDF text extraction + image preparation (Improved)
 *
 * Compatible with chat.php improvements.
 * Returns standardized structure:
 * - ['success'=>true,'type'=>'image','data'=>base64,'mime'=>mime]
 * - ['success'=>true,'type'=>'text','data'=>extracted_text,'mime'=>'text/plain']
 *
 * Notes:
 * - Uses pdftotext if available
 * - Falls back to pure PHP PDF parser (smalot/pdfparser) if pdftotext not available
 * - Resizes images (optional) to control token/cost
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class FileProcessor
{
    private array $cfg;
    private ?Logger $logger;

    public function __construct(array $uploadConfig, ?Logger $logger = null)
    {
        $this->cfg    = $uploadConfig;
        $this->logger = $logger;

        $path = rtrim($this->cfg['storage_path'] ?? (__DIR__ . '/../uploads/'), '/\\') . '/';
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $this->cfg['storage_path'] = $path;
    }

    /**
     * Process uploaded file
     *
     * @param array $file $_FILES['file']
     * @return array standardized
     */
    public function processUpload(array $file): array
    {
        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'Invalid uploaded file.'];
        }

        $name = (string)($file['name'] ?? 'file');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Detect mime
        $mime = $this->detectMime($file['tmp_name']) ?? ($file['type'] ?? '');

        // Store file to uploads folder (for debugging & later processing)
        $storedPath = $this->storeFile($file['tmp_name'], $ext);
        if (!$storedPath) {
            return ['success' => false, 'error' => 'Failed to store uploaded file.'];
        }

        if ($ext === 'pdf' || str_contains((string)$mime, 'pdf')) {
            // Extract text from PDF
            $text = $this->extractPdfText($storedPath);

            // Fallback: If PDF has no extractable text (often scanned), render first page(s) to image for Vision.
            $pdfImg = $this->convertPDFToImage($storedPath);
            if (is_string($pdfImg) && $pdfImg !== '') {
                return [
                    'success' => true,
                    'type'    => 'image',
                    'data'    => $pdfImg,
                    'mime'    => 'image/png',
                    'path'    => $storedPath,
                ];
            }

            if ($text === null || trim($text) === '') {
                return [
                    'success' => false,
                    'error'   => 'لم أستطع استخراج النص من ملف PDF. إذا كان الملف صورة ممسوحة (Scan)، ارفعه كصورة أو PDF قابل للبحث.',
                ];
            }

            // Optional: truncate very long extracted text to protect tokens/cost
            $maxChars = (int)($this->cfg['max_pdf_chars'] ?? 30000);
            if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
                $text = mb_substr($text, 0, $maxChars) . "\n\n[... تم اقتطاع النص بسبب الطول]";
            }

            return [
                'success' => true,
                'type'    => 'text',
                'data'    => $text,
                'mime'    => 'text/plain',
                'path'    => $storedPath,
            ];
        }

        // Image handling
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || str_starts_with((string)$mime, 'image/')) {
            $prepared = $this->prepareImage($storedPath, $ext);

            if (!$prepared['success']) {
                return $prepared;
            }

            return [
                'success' => true,
                'type'    => 'image',
                'data'    => $prepared['base64'],
                'mime'    => $prepared['mime'],
                'path'    => $storedPath,
            ];
        }

        return [
            'success' => false,
            'error'   => 'Unsupported file type.',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────

    private function storeFile(string $tmpPath, string $ext): ?string
    {
        $ext = $ext ?: 'bin';
        $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $this->cfg['storage_path'] . $safeName;

        // move_uploaded_file works only for HTTP uploads; fallback to rename/copy
        if (function_exists('move_uploaded_file') && @move_uploaded_file($tmpPath, $dest)) {
            return $dest;
        }
        if (@rename($tmpPath, $dest)) {
            return $dest;
        }
        if (@copy($tmpPath, $dest)) {
            return $dest;
        }

        if ($this->logger) {
            $this->logger->error('Failed to store upload', [
                'tmp'  => $tmpPath,
                'dest' => $dest,
            ]);
        }

        return null;
    }

    private function detectMime(string $filePath): ?string
    {
        if (!is_file($filePath)) return null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                return $mime ?: null;
            }
        }
        return null;
    }

    /**
     * Prepare image for Vision:
     * - Resize to max_image_dim to reduce cost
     * - Convert to JPEG for consistent encoding (optional)
     */
    private function prepareImage(string $filePath, string $ext): array
    {
        $maxDim = (int)($this->cfg['max_image_dim'] ?? 2048);

        // If GD not available, just base64 original file
        if (!extension_loaded('gd')) {
            $raw = @file_get_contents($filePath);
            if (!is_string($raw) || $raw === '') {
                return ['success' => false, 'error' => 'Failed to read image.'];
            }
            $mime = $this->detectMime($filePath) ?? 'image/jpeg';

            return [
                'success' => true,
                'base64'  => base64_encode($raw),
                'mime'    => $mime,
            ];
        }

        $img = $this->loadImageGD($filePath, $ext);
        if (!$img) {
            // Fallback to raw
            $raw = @file_get_contents($filePath);
            if (!is_string($raw) || $raw === '') {
                return ['success' => false, 'error' => 'Failed to read image.'];
            }
            $mime = $this->detectMime($filePath) ?? 'image/jpeg';

            return [
                'success' => true,
                'base64'  => base64_encode($raw),
                'mime'    => $mime,
            ];
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // Resize if needed
        $newW = $w;
        $newH = $h;

        if ($w > $maxDim || $h > $maxDim) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $newW  = (int)floor($w * $ratio);
            $newH  = (int)floor($h * $ratio);

            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }

        // Encode to JPEG for stable results and smaller size
        ob_start();
        imagejpeg($img, null, 85);
        $jpegData = ob_get_clean();
        imagedestroy($img);

        if (!is_string($jpegData) || $jpegData === '') {
            return ['success' => false, 'error' => 'Failed to encode image.'];
        }

        return [
            'success' => true,
            'base64'  => base64_encode($jpegData),
            'mime'    => 'image/jpeg',
        ];
    }

    private function loadImageGD(string $filePath, string $ext)
    {
        try {
            return match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($filePath),
                'png'         => @imagecreatefrompng($filePath),
                'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
                default       => null,
            };
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning('GD image load failed', [
                    'error' => $e->getMessage(),
                    'file'  => basename($filePath),
                ]);
            }
            return null;
        }
    }

    /**
     * Extract text from PDF
     * Strategy:
     * 1) If "pdftotext" exists on server, use it (best).
     * 2) Otherwise, fallback to Smalot\PdfParser (pure PHP) if available.
     * 3) Otherwise, return null.
     */
    private function extractPdfText(string $pdfPath): ?string
    {
        // 1) Use pdftotext if available AND exec is allowed
        $pdftotext = $this->findBinary('pdftotext');
        if ($pdftotext && $this->isExecAvailable()) {
            $tmpOut = $this->cfg['storage_path'] . 'pdf_' . bin2hex(random_bytes(6)) . '.txt';
            $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpOut) . ' 2>/dev/null';
            $out = [];
            $code = 1;
            @exec($cmd, $out, $code);

            if ($code === 0 && is_file($tmpOut)) {
                $text = @file_get_contents($tmpOut);
                @unlink($tmpOut);

                if (is_string($text)) {
                    $text = trim($text);
                    if ($text !== '') return $text;
                }
            }
        }

        // 2) Pure PHP fallback via Smalot\PdfParser (shared hosting friendly)
        $text = $this->extractPdfTextViaPhpParser($pdfPath);
        if (is_string($text)) {
            $text = trim($text);
            if ($text !== '') return $text;
        }

        // No extractor available
        if ($this->logger) {
            $this->logger->warning('PDF text extraction unavailable', [
                'file' => basename($pdfPath),
            ]);
        }

        return null;
    }

    private function extractPdfTextViaPhpParser(string $pdfPath): ?string
    {
        // Try common autoload locations
        $candidates = [
            __DIR__ . '/../vendor/autoload.php',       // /classes -> /vendor
            __DIR__ . '/../../vendor/autoload.php',    // if structure differs
        ];

        $autoload = null;
        foreach ($candidates as $p) {
            if (is_file($p)) {
                $autoload = $p;
                break;
            }
        }

        if ($autoload) {
            // Avoid double include warnings
            @require_once $autoload;
        }

        // If library not installed, skip
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($pdfPath);
            $text   = (string)$pdf->getText();
            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning('PHP PDF parser failed', [
                    'error' => $e->getMessage(),
                    'file'  => basename($pdfPath),
                ]);
            }
            return null;
        }
    }

    private function isExecAvailable(): bool
    {
        // If exec is disabled in php.ini disable_functions, return false
        $disabled = (string)ini_get('disable_functions');
        if ($disabled) {
            $list = array_map('trim', explode(',', $disabled));
            if (in_array('exec', $list, true) || in_array('shell_exec', $list, true)) {
                return false;
            }
        }
        return function_exists('exec');
    }


    /**
     * Convert PDF (first page(s)) to a PNG image and return base64.
     * This restores the old behavior: scanned PDFs still work via Vision.
     * Requires either Imagick (preferred on shared hosting) or Ghostscript (exec).
     */
    private function convertPDFToImage(string $pdfPath): ?string
    {
        $maxPages = (int)($this->cfg['pdf_render_pages'] ?? 1);
        $dpi      = (int)($this->cfg['pdf_render_dpi'] ?? 180);

        // 1) Imagick (best if available)
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->setResolution($dpi, $dpi);

                // Read first N pages and stitch vertically into one image (better for OCR/Vision).
                $range = sprintf('[0-%d]', max(0, $maxPages - 1));
                $im->readImage($pdfPath . $range);

                $im->setImageFormat('png');

                $combined = $im->appendImages(true);
                $combined->setImageFormat('png');

                $blob = $combined->getImageBlob();

                $combined->clear();
                $combined->destroy();
                $im->clear();
                $im->destroy();

                if (is_string($blob) && $blob !== '') {
                    return base64_encode($blob);
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warning('Imagick PDF->image failed', [
                        'error' => $e->getMessage(),
                        'file'  => basename($pdfPath),
                    ]);
                }
            }
        }

        // 2) Ghostscript (if exec allowed)
        if ($this->isExecAvailable()) {
            $gs = $this->findBinary('gs') ?? $this->findBinary('gswin64c') ?? $this->findBinary('gswin32c');
            if ($gs) {
                $outputFile = $this->cfg['storage_path'] . 'pdfimg_' . bin2hex(random_bytes(6)) . '.png';

                $cmd = sprintf(
                    '%s -dNOPAUSE -dBATCH -sDEVICE=png16m -r%d -dFirstPage=1 -dLastPage=%d -sOutputFile=%s %s 2>/dev/null',
                    escapeshellcmd($gs),
                    $dpi,
                    max(1, $maxPages),
                    escapeshellarg($outputFile),
                    escapeshellarg($pdfPath)
                );

                $out = [];
                $code = 1;
                @exec($cmd, $out, $code);

                if ($code === 0 && is_file($outputFile) && filesize($outputFile) > 0) {
                    $blob = @file_get_contents($outputFile);
                    @unlink($outputFile);
                    if (is_string($blob) && $blob !== '') {
                        return base64_encode($blob);
                    }
                } else {
                    @unlink($outputFile);
                }
            }
        }

        return null;
    }

    private function findBinary(string $name): ?string
    {
        $paths = [
            '/usr/bin/' . $name,
            '/bin/' . $name,
            '/usr/local/bin/' . $name,
        ];

        foreach ($paths as $p) {
            if (is_file($p) && is_executable($p)) return $p;
        }

        // Try "which" only if exec is available
        if ($this->isExecAvailable()) {
            $cmd = 'which ' . escapeshellarg($name) . ' 2>/dev/null';
            $out = [];
            $code = 1;
            @exec($cmd, $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $p = trim($out[0]);
                if (is_file($p) && is_executable($p)) return $p;
            }
        }

        return null;
    }
}