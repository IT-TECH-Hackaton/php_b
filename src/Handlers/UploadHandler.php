<?php

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class UploadHandler
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    public function uploadImage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        
        if (!isset($uploadedFiles['image'])) {
            $response->getBody()->write(json_encode(['error' => 'Файл не найден']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $file = $uploadedFiles['image'];
        
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка загрузки файла']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $response->getBody()->write(json_encode(['error' => 'Размер файла не должен превышать 10MB']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if ($file->getSize() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Файл пустой']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $filename = $file->getClientFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($ext, self::ALLOWED_EXTENSIONS)) {
            $response->getBody()->write(json_encode(['error' => 'Недопустимый формат файла. Разрешены: jpg, jpeg, png, gif, webp, svg']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stream = $file->getStream();
        $buffer = $stream->read(512);
        $stream->rewind();
        
        $mimeType = $this->detectMimeType($buffer);
        
        if (!$this->isValidImageFile($buffer, $mimeType, $ext)) {
            $response->getBody()->write(json_encode(['error' => 'Недопустимый тип файла. Файл должен быть изображением (JPEG, PNG, GIF, WebP)']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $uploadDir = __DIR__ . '/../../uploads';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                $response->getBody()->write(json_encode(['error' => 'Ошибка при создании директории']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
        }
        
        $newFilename = Uuid::uuid4()->toString() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $newFilename;
        
        $destination = fopen($filePath, 'wb');
        if (!$destination) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка при создании файла']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $stream->rewind();
        while (!$stream->eof()) {
            fwrite($destination, $stream->read(8192));
        }
        fclose($destination);
        
        $imageURL = '/uploads/' . $newFilename;
        $response->getBody()->write(json_encode(['imageURL' => $imageURL]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function detectMimeType(string $buffer): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $buffer);
        finfo_close($finfo);
        return $mimeType ?: 'application/octet-stream';
    }

    private function isValidImageFile(string $buffer, string $mimeType, string $ext): bool
    {
        $extLower = strtolower($ext);
        
        if ($extLower === 'svg') {
            $svgMagic = '<svg';
            $svgMagicAlt = '<?xml';
            $svgContent = strtolower(substr($buffer, 0, min(strlen($buffer), 100)));
            return strpos($buffer, $svgMagic) === 0 || 
                   strpos($buffer, $svgMagicAlt) === 0 || 
                   strpos($svgContent, '<svg') !== false;
        }
        
        $mimeAllowed = false;
        foreach (self::ALLOWED_MIME_TYPES as $allowedMime) {
            if (strpos($mimeType, $allowedMime) === 0) {
                $mimeAllowed = true;
                break;
            }
        }
        
        if (!$mimeAllowed) {
            return false;
        }
        
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extLower, $allowedExts)) {
            return false;
        }
        
        if (strlen($buffer) < 4) {
            return false;
        }
        
        $jpegMagic = "\xFF\xD8\xFF";
        $pngMagic = "\x89\x50\x4E\x47";
        $gifMagic = "\x47\x49\x46\x38";
        $webpMagic = "\x52\x49\x46\x46";
        
        if (strpos($buffer, $jpegMagic) === 0) {
            return in_array($extLower, ['jpg', 'jpeg']);
        }
        if (strpos($buffer, $pngMagic) === 0) {
            return $extLower === 'png';
        }
        if (strpos($buffer, $gifMagic) === 0) {
            return $extLower === 'gif';
        }
        if (strpos($buffer, $webpMagic) === 0 && strlen($buffer) >= 12) {
            if (substr($buffer, 8, 4) === "WEBP") {
                return $extLower === 'webp';
            }
        }
        
        return false;
    }
}
