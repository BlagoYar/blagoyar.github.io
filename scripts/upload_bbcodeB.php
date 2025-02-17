<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

    define('UPLOAD_DIR_IMAGES', getenv('UPLOAD_DIR_IMAGES'));
    define('UPLOAD_DIR_FILES', getenv('UPLOAD_DIR_FILES'));
    define('VALID_TOKEN', getenv('VALID_TOKEN_BBCODEb'));

    class FileUploader {
        private $validToken;
        private $imageConverter;

        public function __construct($token, $imageConverter) {
            $this->validToken = $token;
            $this->imageConverter = $imageConverter;
        }

        public function authorize() {
            $headers = apache_request_headers();
            $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
            if ($authHeader !== 'Bearer ' . $this->validToken) {
                $this->unauthorizedResponse();
            }
        }

        private function unauthorizedResponse() {
            header('HTTP/1.1 401 Unauthorized');
            readfile("/usr/share/nginx/html/401.html");
            exit;
        }

        public function uploadFile($file) {
            try {
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $uploadDir = in_array($file_extension, $this->getImageExtensions()) ? UPLOAD_DIR_IMAGES : UPLOAD_DIR_FILES;
                $uploadFile = $uploadDir . basename($file['name']);

                // Логирование директории и имени файла
                error_log("Попытка загрузить файл в директорию: $uploadDir");
                error_log("Имя файла: " . basename($file['name']));

                // Проверяем права на директорию
                if (!is_writable($uploadDir)) {
                    error_log("Ошибка: Директория $uploadDir недоступна для записи.");
                    return "error: Директория недоступна для записи.";
                }

                while (file_exists($uploadFile)) {
                    $path_info = pathinfo($uploadFile);
                    $uploadFile = $path_info['dirname'] . '/' . $path_info['filename'] . '_' . $this->generateRandomString() . '.' . $path_info['extension'];
                }

                if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                    error_log("Файл успешно загружен: $uploadFile");

                    if (in_array($file_extension, $this->getImageExtensions())) {
                        $randomString = $this->generateRandomString(16);
                        $thumbnailFile = $uploadDir . 'thumb_' . $randomString . '.png';

                        if ($file_extension === 'svg') {
                            $thumbnail = $this->imageConverter->createSvgThumbnail($uploadFile, filesize($uploadFile));
                        } else {
                            $thumbnail = $this->imageConverter->createThumbnail($uploadFile, filesize($uploadFile), $file_extension);
                        }

                        $thumbnail->writeImage($thumbnailFile);
                        error_log("Миниатюра успешно создана: $thumbnailFile");

                        $uploadFileURI = rawurlencode(basename($uploadFile));
                        $thumbnailFileURI = rawurlencode(basename($thumbnailFile));

                        return '[url=https://upl.pp.ua/' . $uploadFileURI . '][img]https://upl.pp.ua/' . $thumbnailFileURI . '[/img][/url]';
                    } else {
                        $uploadFileURI = rawurlencode(basename($uploadFile));
                        return 'https://upl.pp.ua/' . $uploadFileURI;
                    }
                } else {
                    throw new Exception("Ошибка при перемещении файла.");
                }
            } catch (Exception $e) {
                error_log("Ошибка: " . $e->getMessage());
                return "error: Произошла ошибка при обработке файла.";
            }
        }

        private function generateRandomString($length = 4) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
            return $randomString;
        }

        private function getImageExtensions() {
            return ['jpg', 'jpeg', 'jpe', 'jif', 'jfif', 'png', 'bmp', 'gif', 'tiff', 'tif', 'webp', 'heic', 'heif', 'avif', 'svg', 'ico'];
        }
    }

    class ImageConverterPrivate {
        private $thumbCreatingParams;

        public function __construct($thumbCreatingParams) {
            $this->thumbCreatingParams = $thumbCreatingParams;
        }

    public function createThumbnail($imagePath, $fileSize, $fileExtension) {
        $image = new Imagick($imagePath);

        if ($image->getNumberImages() > 1 && strtolower($fileExtension) === 'ico') {
            $maxWidth = 0;
            $maxHeight = 0;
            $imageIndex = 0;
            foreach ($image as $index => $frame) {
                $frameWidth = $frame->getImageWidth();
                $frameHeight = $frame->getImageHeight();
                if ($frameWidth > $maxWidth || $frameHeight > $maxHeight) {
                    $maxWidth = $frameWidth;
                    $maxHeight = $frameHeight;
                    $imageIndex = $index;
                }
            }
            $image->setIteratorIndex($imageIndex);
        }

        $newWidth = $image->getImageWidth();
        $newHeight = $image->getImageHeight();
        $thumbWidth = $this->thumbCreatingParams['Width'];

        if ($this->thumbCreatingParams['ResizeMode'] === 'trByWidth') {
            $thumbHeight = (int) round(($thumbWidth / $newWidth) * $newHeight);
        } elseif ($this->thumbCreatingParams['ResizeMode'] === 'trByHeight') {
            $thumbHeight = $newHeight;
            $thumbWidth = $newWidth;
        }

        $overlayBottomHeight = 30;
        $frameThickness = 3;
        $padding = 5;
        $transparencyPadding = 5;

        $thumbCopy = clone $image;
        $thumbCopy->thumbnailImage($thumbWidth, $thumbHeight, true);
        $thumbCopy->roundCorners(3, 3);

        $backgroundWidth = $thumbWidth + 6 + 2 * $transparencyPadding;
        $backgroundHeight = max($thumbHeight + $overlayBottomHeight + 6 + 2 * $transparencyPadding, $thumbHeight + 33 + 2 * $transparencyPadding);

        $background = new Imagick();
        $background->newImage($backgroundWidth, $backgroundHeight, new ImagickPixel('none'));
        $background->setImageFormat('png');

        $frame = new Imagick();
        $frame->newImage($backgroundWidth - 2 * $transparencyPadding, $backgroundHeight - 2 * $transparencyPadding, new ImagickPixel('none'));
        $frame->setImageFormat('png');

        $colorLayer = new Imagick();
        $colorLayer->newImage($frame->getImageWidth(), $frame->getImageHeight(), new ImagickPixel('#222222'));
        $colorLayer->setImageFormat('png');

        $frameMask = new Imagick();
        $frameMask->newImage($frame->getImageWidth(), $frame->getImageHeight(), new ImagickPixel('white'));
        $frameMask->setImageFormat('png');
        $frameMask->roundCorners(3, 3);

        $colorLayer->compositeImage($frameMask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $frame->compositeImage($colorLayer, Imagick::COMPOSITE_OVER, 0, 0);
        $background->compositeImage($frame, Imagick::COMPOSITE_OVER, $transparencyPadding, $transparencyPadding);

        $thumbX = 3 + $transparencyPadding;
        $thumbY = 3 + $transparencyPadding;

        $background->compositeImage($thumbCopy, Imagick::COMPOSITE_OVER, $thumbX, $thumbY);

        $draw = new ImagickDraw();
        $draw->setFillColor('#FFFFFF');
        $draw->setFont('/usr/share/fonts/truetype/tahoma/tahoma.ttf');
        $draw->setFontSize(12);
        $draw->setGravity(Imagick::GRAVITY_NORTHWEST);

        $textX = 10 + $transparencyPadding;
        $textY = 0;
        $textY += $backgroundHeight - $overlayBottomHeight + 5 - $transparencyPadding;
        $fileSizeKB = round($fileSize / 1024, 1);

        if ($fileSizeKB >= 1024) {
            $fileSizeMB = number_format($fileSizeKB / 1024, 2, '.', '');
            $text = "{$newWidth}x{$newHeight} [{$fileSizeMB} Mb]";
        } else {
            $text = "{$newWidth}x{$newHeight} [{$fileSizeKB} Kb]";
        }

        $background->annotateImage($draw, $textX, $textY, 0, $text);

        $lensImagePath = '/var/www/html/upl.pp.ua/scripts/lens-white.png';
        $lens = new Imagick($lensImagePath);
        $lensX = $background->getImageWidth() - $lens->getImageWidth() - 12 - $transparencyPadding;
        $lensY = $background->getImageHeight() - $lens->getImageHeight() - 12 - $transparencyPadding;
        $background->compositeImage($lens, Imagick::COMPOSITE_OVER, $lensX, $lensY);

        // $thumbCopy->writeImage('/tmp/thumb_with_mask.png');
        // $background->writeImage('/tmp/final_output.png');

        return $background;
    }

         public function createSvgThumbnail($imagePath, $fileSize) {
        $image = new Imagick($imagePath);

        if ($image->getNumberImages() > 1 && strtolower($fileExtension) === 'ico') {
            $maxWidth = 0;
            $maxHeight = 0;
            $imageIndex = 0;
            foreach ($image as $index => $frame) {
                $frameWidth = $frame->getImageWidth();
                $frameHeight = $frame->getImageHeight();
                if ($frameWidth > $maxWidth || $frameHeight > $maxHeight) {
                    $maxWidth = $frameWidth;
                    $maxHeight = $frameHeight;
                    $imageIndex = $index;
                }
            }
            $image->setIteratorIndex($imageIndex);
        }

        $newWidth = $image->getImageWidth();
        $newHeight = $image->getImageHeight();
        $thumbWidth = $this->thumbCreatingParams['Width'];

        if ($this->thumbCreatingParams['ResizeMode'] === 'trByWidth') {
            $thumbHeight = (int) round(($thumbWidth / $newWidth) * $newHeight);
        } elseif ($this->thumbCreatingParams['ResizeMode'] === 'trByHeight') {
            $thumbHeight = $newHeight;
            $thumbWidth = $newWidth;
        }

        $overlayBottomHeight = 30;
        $frameThickness = 3;
        $padding = 5;
        $transparencyPadding = 5;

        $thumbCopy = clone $image;
        $thumbCopy->thumbnailImage($thumbWidth, $thumbHeight, true);
        $thumbCopy->roundCorners(3, 3);

        $backgroundWidth = $thumbWidth + 6 + 2 * $transparencyPadding;
        $backgroundHeight = max($thumbHeight + $overlayBottomHeight + 6 + 2 * $transparencyPadding, $thumbHeight + 33 + 2 * $transparencyPadding);

        $background = new Imagick();
        $background->newImage($backgroundWidth, $backgroundHeight, new ImagickPixel('none'));
        $background->setImageFormat('png');

        $frame = new Imagick();
        $frame->newImage($backgroundWidth - 2 * $transparencyPadding, $backgroundHeight - 2 * $transparencyPadding, new ImagickPixel('none'));
        $frame->setImageFormat('png');

        $colorLayer = new Imagick();
        $colorLayer->newImage($frame->getImageWidth(), $frame->getImageHeight(), new ImagickPixel('#222222'));
        $colorLayer->setImageFormat('png');

        $frameMask = new Imagick();
        $frameMask->newImage($frame->getImageWidth(), $frame->getImageHeight(), new ImagickPixel('white'));
        $frameMask->setImageFormat('png');
        $frameMask->roundCorners(3, 3);

        $colorLayer->compositeImage($frameMask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $frame->compositeImage($colorLayer, Imagick::COMPOSITE_OVER, 0, 0);
        $background->compositeImage($frame, Imagick::COMPOSITE_OVER, $transparencyPadding, $transparencyPadding);

        $thumbX = 3 + $transparencyPadding;
        $thumbY = 3 + $transparencyPadding;

        $background->compositeImage($thumbCopy, Imagick::COMPOSITE_OVER, $thumbX, $thumbY);

        $draw = new ImagickDraw();
        $draw->setFillColor('#FFFFFF');
        $draw->setFont('/usr/share/fonts/truetype/tahoma/tahoma.ttf');
        $draw->setFontSize(12);
        $draw->setGravity(Imagick::GRAVITY_NORTHWEST);

        $textX = 10 + $transparencyPadding;
        $textY = 0;
        $textY += $backgroundHeight - $overlayBottomHeight + 5 - $transparencyPadding;
        $fileSizeKB = round($fileSize / 1024, 1);

        if ($fileSizeKB >= 1024) {
            $fileSizeMB = number_format($fileSizeKB / 1024, 2, '.', '');
            $text = "{$newWidth}x{$newHeight} [{$fileSizeMB} Mb]";
        } else {
            $text = "{$newWidth}x{$newHeight} [{$fileSizeKB} Kb]";
        }

        $background->annotateImage($draw, $textX, $textY, 0, $text);

        $lensImagePath = '/var/www/html/upl.pp.ua/scripts/lens-white.png';
        $lens = new Imagick($lensImagePath);
        $lensX = $background->getImageWidth() - $lens->getImageWidth() - 12 - $transparencyPadding;
        $lensY = $background->getImageHeight() - $lens->getImageHeight() - 12 - $transparencyPadding;
        $background->compositeImage($lens, Imagick::COMPOSITE_OVER, $lensX, $lensY);

        // $thumbCopy->writeImage('/tmp/thumb_with_mask.png');
        // $background->writeImage('/tmp/final_output.png');

        return $background;
        }
    }

    $thumbCreatingParams = [
        'Width' => 180,
        'Height' => 180,
        'ResizeMode' => 'trByWidth',
        'DrawFrame' => true
    ];

    $imageConverter = new ImageConverterPrivate($thumbCreatingParams);
    $fileUploader = new FileUploader(VALID_TOKEN, $imageConverter);
    $fileUploader->authorize();

    // file_put_contents("upload_log.txt", print_r($_FILES, true), FILE_APPEND);
    // file_put_contents("upload_debug_log.txt", json_encode($_FILES, JSON_PRETTY_PRINT), FILE_APPEND);

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
        $responses = [];
        foreach ($_FILES['files']['tmp_name'] as $index => $tmpName) {
            $file = [
                'name' => $_FILES['files']['name'][$index],
                'type' => $_FILES['files']['type'][$index],
                'tmp_name' => $tmpName,
                'error' => $_FILES['files']['error'][$index],
                'size' => $_FILES['files']['size'][$index]
            ];
            $responses[] = $fileUploader->uploadFile($file);
        }
        echo implode("\n", $responses);
    } else {
        echo "error: Неверный метод запроса или отсутствует файл.";
    }
?>