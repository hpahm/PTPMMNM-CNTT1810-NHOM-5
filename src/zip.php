<?php
declare(strict_types=1);

/**
 * Pure-PHP ZIP reader/writer using built-in zlib, with ZipArchive fast-path when available.
 * Eliminates external dependencies like 7z.exe on Ubuntu VPS and Windows.
 */

function zipReadEntries(string $zipPath, ?callable $filter = null, int $maxEntryBytes = 10_485_760, int $maxTotalBytes = 31_457_280): array {
    if (!is_file($zipPath)) {
        throw new RuntimeException('Không tìm thấy tệp nén.');
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Không thể mở tệp DOCX/ZIP.');
        }
        $entries = [];
        $totalBytes = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }
                if ($filter !== null && !$filter($name)) {
                    continue;
                }
                $stat = $zip->statIndex($i);
                $size = (int)($stat['size'] ?? 0);
                if ($size > $maxEntryBytes || ($totalBytes + $size) > $maxTotalBytes) {
                    throw new RuntimeException('Nội dung tệp nén vượt quá giới hạn bộ nhớ cho phép.');
                }
                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    throw new RuntimeException('Không thể giải nén mục "' . $name . '".');
                }
                $totalBytes += strlen($content);
                $entries[$name] = $content;
            }
        } finally {
            $zip->close();
        }
        return $entries;
    }

    $raw = file_get_contents($zipPath);
    if ($raw === false || strlen($raw) < 22) {
        throw new RuntimeException('Không thể đọc tệp DOCX/ZIP.');
    }

    $eocdPos = strrpos($raw, "\x50\x4b\x05\x06");
    if ($eocdPos === false) {
        throw new RuntimeException('Tệp DOCX/ZIP không hợp lệ.');
    }
    $eocd = unpack('vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLen', substr($raw, $eocdPos + 4, 18));
    if (!$eocd) {
        throw new RuntimeException('Không thể đọc cấu trúc thư mục ZIP.');
    }

    $offset = (int)$eocd['cdOffset'];
    $count = (int)$eocd['totalEntries'];
    $entries = [];
    $totalBytes = 0;

    for ($i = 0; $i < $count; $i++) {
        if (substr($raw, $offset, 4) !== "\x50\x4b\x01\x02") {
            break;
        }
        $hdr = unpack(
            'vverMade/vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdiskStart/vinternAttr/VextAttr/VlocalOffset',
            substr($raw, $offset + 4, 42)
        );
        if (!$hdr) {
            break;
        }
        $name = substr($raw, $offset + 46, (int)$hdr['nameLen']);
        $offset += 46 + (int)$hdr['nameLen'] + (int)$hdr['extraLen'] + (int)$hdr['commentLen'];

        if (str_ends_with($name, '/') || ($filter !== null && !$filter($name))) {
            continue;
        }

        $uncompSize = (int)$hdr['uncompSize'];
        if ($uncompSize > $maxEntryBytes || ($totalBytes + $uncompSize) > $maxTotalBytes) {
            throw new RuntimeException('Nội dung tệp nén vượt quá giới hạn bộ nhớ cho phép.');
        }

        $localOffset = (int)$hdr['localOffset'];
        if (substr($raw, $localOffset, 4) !== "\x50\x4b\x03\x04") {
            throw new RuntimeException('Cấu trúc dữ liệu ZIP bị hỏng.');
        }
        $localHdr = unpack('vnameLen/vextraLen', substr($raw, $localOffset + 26, 4));
        $dataStart = $localOffset + 30 + (int)$localHdr['nameLen'] + (int)$localHdr['extraLen'];
        $compData = substr($raw, $dataStart, (int)$hdr['compSize']);

        $method = (int)$hdr['method'];
        if ($method === 0) {
            $content = $compData;
        } elseif ($method === 8) {
            $content = @gzinflate($compData);
            if ($content === false) {
                throw new RuntimeException('Không thể giải nén mục "' . $name . '".');
            }
        } else {
            throw new RuntimeException('Phương thức nén ZIP không được hỗ trợ.');
        }

        if (strlen($content) > $maxEntryBytes || ($totalBytes + strlen($content)) > $maxTotalBytes) {
            throw new RuntimeException('Nội dung tệp nén vượt quá giới hạn bộ nhớ cho phép.');
        }
        $totalBytes += strlen($content);
        $entries[$name] = $content;
    }

    return $entries;
}

function zipDosTime(int $timestamp): array {
    $dt = getdate($timestamp);
    $dosTime = (($dt['hours'] & 0x1F) << 11) | (($dt['minutes'] & 0x3F) << 5) | (($dt['seconds'] >> 1) & 0x1F);
    $dosDate = ((max(1980, $dt['year']) - 1980) << 9) | (($dt['mon'] & 0x0F) << 5) | ($dt['mday'] & 0x1F);
    return [$dosTime, $dosDate];
}

/**
 * Write a ZIP archive from items: [['name' => 'dir/file.txt', 'data' => '...'] or ['name' => '...', 'path' => '/abs/path']].
 */
function zipWriteArchive(string $zipPath, array $items): void {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không thể tạo tệp ZIP.');
        }
        try {
            foreach ($items as $item) {
                $name = ltrim(str_replace('\\', '/', (string)$item['name']), '/');
                if (isset($item['path'])) {
                    $zip->addFile((string)$item['path'], $name);
                } else {
                    $zip->addFromString($name, (string)($item['data'] ?? ''));
                }
            }
        } finally {
            $zip->close();
        }
        return;
    }

    $fh = fopen($zipPath, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Không thể tạo tệp ZIP.');
    }

    $centralDir = '';
    $offset = 0;
    $count = 0;
    [$dosTime, $dosDate] = zipDosTime(time());

    try {
        foreach ($items as $item) {
            $name = ltrim(str_replace('\\', '/', (string)$item['name']), '/');
            $data = isset($item['path']) ? file_get_contents((string)$item['path']) : (string)($item['data'] ?? '');
            if ($data === false) {
                throw new RuntimeException('Không thể đọc tệp nguồn khi nén ZIP.');
            }

            $uncompSize = strlen($data);
            $crc = crc32($data);
            $compressed = gzdeflate($data, 6);
            if ($compressed === false || strlen($compressed) >= $uncompSize) {
                $method = 0;
                $payload = $data;
            } else {
                $method = 8;
                $payload = $compressed;
            }
            $compSize = strlen($payload);
            $nameLen = strlen($name);

            $localHeader = "\x50\x4b\x03\x04"
                . pack('vvvvvVVVvv', 20, 0x0800, $method, $dosTime, $dosDate, $crc, $compSize, $uncompSize, $nameLen, 0)
                . $name;

            fwrite($fh, $localHeader);
            fwrite($fh, $payload);

            $centralDir .= "\x50\x4b\x01\x02"
                . pack('vvvvvvVVVvvvvvVV', 0, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $compSize, $uncompSize, $nameLen, 0, 0, 0, 0, 0, $offset)
                . $name;

            $offset += strlen($localHeader) + $compSize;
            $count++;
        }

        $cdSize = strlen($centralDir);
        fwrite($fh, $centralDir);
        fwrite($fh, "\x50\x4b\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, $cdSize, $offset, 0));
    } finally {
        fclose($fh);
    }
}

function zipReplaceEntry(string $zipPath, string $entryName, string $newContent): void {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Không thể mở tệp DOCX.');
        }
        try {
            if (!$zip->addFromString($entryName, $newContent)) {
                throw new RuntimeException('Không thể cập nhật nội dung DOCX.');
            }
        } finally {
            $zip->close();
        }
        return;
    }

    $entries = zipReadEntries($zipPath);
    $entries[$entryName] = $newContent;
    $items = [];
    foreach ($entries as $name => $data) {
        $items[] = ['name' => $name, 'data' => $data];
    }
    $tmp = $zipPath . '.tmp-' . bin2hex(random_bytes(4));
    zipWriteArchive($tmp, $items);
    if (!rename($tmp, $zipPath)) {
        @unlink($tmp);
        throw new RuntimeException('Không thể lưu tệp DOCX đã chỉnh sửa.');
    }
}

