<?php

namespace Tests\Support;

/**
 * Hand-builds minimal valid PDF files for tests — no PDF library/tool is
 * needed to produce real, parseable fixtures, and none of this is (or
 * should ever be) real CV content.
 */
trait BuildsTestPdfs
{
    private function buildPdfBytes(array $objects, int $catalogObjNum, ?array $encryptDict = null, ?string $fileIdHex = null): string
    {
        $out = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $maxNum = max(array_keys($objects));
        $out .= 'xref'."\n0 ".($maxNum + 1)."\n";
        $out .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxNum; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 00000 f \n";
        }

        $trailerExtra = '';

        if ($encryptDict !== null) {
            $trailerExtra .= "/Encrypt {$encryptDict['ref']} 0 R ";
        }

        if ($fileIdHex !== null) {
            $trailerExtra .= "/ID [<{$fileIdHex}> <{$fileIdHex}>] ";
        }

        $out .= 'trailer'."\n<< /Size ".($maxNum + 1)." /Root {$catalogObjNum} 0 R {$trailerExtra}>>\n";
        $out .= 'startxref'."\n{$xrefOffset}\n%%EOF";

        return $out;
    }

    /**
     * A single-page PDF with a real, extractable text content stream.
     */
    private function textPdf(string $text): string
    {
        $lines = explode("\n", $text);
        $stream = "BT /F1 12 Tf 50 750 Td 14 TL\n";

        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= "($escaped) Tj T*\n";
        }

        $stream .= 'ET';

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /MediaBox [0 0 612 792] /Contents 4 0 R >>';
        $objects[4] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        return $this->buildPdfBytes($objects, 1);
    }

    /**
     * A single-page PDF whose only content is an embedded raster image —
     * no text layer at all, like a scanned page.
     */
    private function scannedImageOnlyPdf(): string
    {
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgICQsNCgkJCw8MDAsOFBAREBMVExcYHRwdGxoYGCEqJSYhKSgyNDU0MjQ1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTX/2wBDAQ4ODg8RERYRERoaGBYaJCMcHBsnLCwoKzQ4Nzc3NDc1OT1BQj4+TE5TU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NT/8AAEQgAAgACAwEiAAIRAQMRAf/EABQAAQAAAAAAAAAAAAAAAAAAAAD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9sAQwABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAH/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /Resources << /XObject << /Im0 5 0 R >> >> /MediaBox [0 0 612 792] /Contents 4 0 R >>';
        $stream = 'q 400 0 0 400 100 300 cm /Im0 Do Q';
        $objects[4] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
        $objects[5] = '<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream";

        return $this->buildPdfBytes($objects, 1);
    }

    /**
     * A multi-page PDF padded well past a given size with distinct
     * oversized embedded images per page (each via a JPEG COM marker, not
     * touching decodable pixels), and real, distinguishable text on each
     * page so a "first N pages only" cap can be verified directly.
     */
    private function manyPagePdf(int $pageCount, int $perImageExtraBytes = 0): string
    {
        $baseJpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgICQsNCgkJCw8MDAsOFBAREBMVExcYHRwdGxoYGCEqJSYhKSgyNDU0MjQ1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTX/2wBDAQ4ODg8RERYRERoaGBYaJCMcHBsnLCwoKzQ4Nzc3NDc1OT1BQj4+TE5TU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NT/8AAEQgAAgACAwEiAAIRAQMRAf/EABQAAQAAAAAAAAAAAAAAAAAAAAD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9sAQwABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAH/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];

        for ($p = 0; $p < $pageCount; $p++) {
            $kids[] = (3 + $p).' 0 R';
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count $pageCount >>";

        $pageStart = 3;

        for ($p = 0; $p < $pageCount; $p++) {
            $pageNum = $pageStart + $p;
            $contentNum = $pageStart + $pageCount + $p;
            $imgNum = $pageStart + $pageCount * 2 + $p;

            $objects[$pageNum] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 '.($pageStart + $pageCount * 3)." 0 R >> /XObject << /Im0 $imgNum 0 R >> >> /MediaBox [0 0 612 792] /Contents $contentNum 0 R >>";

            $marker = 'PAGE_MARKER_'.($p + 1);
            $stream = "q 50 0 0 50 10 10 cm /Im0 Do Q\nBT /F1 12 Tf 50 750 Td ($marker) Tj ET";
            $objects[$contentNum] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";

            $img = $perImageExtraBytes > 0 ? $this->paddedJpeg($baseJpeg, $perImageExtraBytes) : $baseJpeg;
            $objects[$imgNum] = '<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($img)." >>\nstream\n{$img}\nendstream";
        }

        $objects[$pageStart + $pageCount * 3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        return $this->buildPdfBytes($objects, 1);
    }

    private function paddedJpeg(string $base, int $extraBytes): string
    {
        $comPayload = str_repeat('X', $extraBytes);
        $comMarker = "\xFF\xFE".pack('n', strlen($comPayload) + 2).$comPayload;

        return substr($base, 0, 2).$comMarker.substr($base, 2);
    }

    /**
     * A genuinely password-protected PDF (PDF 1.4 standard security
     * handler, RC4-40 — ISO 32000-1 Algorithm 2/3Hand), not just one that
     * looks like it. No PDF library/tool is available to produce this, so
     * the algorithm is hand-implemented directly.
     */
    private function encryptedPdf(): string
    {
        $rc4 = function (string $key, string $data): string {
            $s = range(0, 255);
            $j = 0;
            $keyLen = strlen($key);

            for ($i = 0; $i < 256; $i++) {
                $j = ($j + $s[$i] + ord($key[$i % $keyLen])) % 256;
                [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            }

            $i = $j = 0;
            $out = '';

            for ($n = 0; $n < strlen($data); $n++) {
                $i = ($i + 1) % 256;
                $j = ($j + $s[$i]) % 256;
                [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
                $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) % 256]);
            }

            return $out;
        };

        $pad = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
        $userPassword = 'test-password';
        $ownerPassword = 'test-owner-password';
        $permissions = -44;
        $fileIdBytes = random_bytes(16);
        $fileIdHex = bin2hex($fileIdBytes);

        $ownerKey = $rc4(substr(md5(substr($ownerPassword.$pad, 0, 32), true), 0, 5), substr($userPassword.$pad, 0, 32));
        $encKey = substr(md5(substr($userPassword.$pad, 0, 32).$ownerKey.pack('V', $permissions).$fileIdBytes, true), 0, 5);
        $userKey = $rc4($encKey, md5($pad.$fileIdBytes, true));

        $objectKey = fn (int $objNum, int $gen) => substr(md5($encKey.substr(pack('V', $objNum), 0, 3).substr(pack('V', $gen), 0, 2), true), 0, min(16, strlen($encKey) + 5));

        $plainStream = 'BT /F1 12 Tf 50 750 Td (encrypted) Tj ET';
        $encryptedStream = $rc4($objectKey(4, 0), $plainStream);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /MediaBox [0 0 612 792] /Contents 4 0 R >>';
        $objects[4] = '<< /Length '.strlen($encryptedStream)." >>\nstream\n{$encryptedStream}\nendstream";
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[6] = '<< /Filter /Standard /V 1 /R 2 /O <'.bin2hex($ownerKey).'> /U <'.bin2hex($userKey)."> /P {$permissions} >>";

        return $this->buildPdfBytes($objects, 1, ['ref' => 6], $fileIdHex);
    }
}
