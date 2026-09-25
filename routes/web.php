<?php

ini_set('display_errors', '0');
ini_set('upload_max_filesize', '12M');
ini_set('post_max_size', '13M');
ini_set('max_execution_time', '60');

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/scanner', function () {
    return view('scanner'); 
});

// 1. Rute untuk Memindai Gambar dengan AI (Raw cURL murni)
Route::post('/api/scan', function (Request $request) {
    if (function_exists('ob_clean')) { ob_clean(); }
    ini_set('display_errors', 0);
    $request->validate([
        'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:12288|dimensions:max_width=6000,max_height=6000',
    ]);

    $image = $request->file('image');
    $imagePath = $image->getRealPath();

    $info = @getimagesize($imagePath);
    if ($info === false) {
        return response()->json(['error' => 'File gambar tidak valid atau korup.'], 422);
    }
    [$width, $height, $type] = $info;

    if ($width <= 0 || $height <= 0) {
        return response()->json(['error' => 'Dimensi gambar tidak valid.'], 422);
    }

    // Cegah OOM: tolak gambar dengan total piksel terlalu besar (~25MP).
    if (($width * $height) > 25000000) {
        return response()->json(['error' => 'Resolusi gambar terlalu besar. Maksimal 6000x6000 piksel.'], 422);
    }

    if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return response()->json(['error' => 'Format gambar harus JPEG, PNG, atau WebP.'], 422);
    }

    $maxDim = 1280;

    if ($width > $maxDim || $height > $maxDim) {
        $ratio = $width / $height;
        if ($ratio > 1) {
            $newWidth = $maxDim;
            $newHeight = (int) round($maxDim / $ratio);
        } else {
            $newHeight = $maxDim;
            $newWidth = (int) round($maxDim * $ratio);
        }
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    $newWidth = max(1, (int) $newWidth);
    $newHeight = max(1, (int) $newHeight);

    $src = null;
    $dst = null;
    $compressedImageBinary = false;
    $obLevel = ob_get_level();

    try {
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
            IMAGETYPE_PNG => @imagecreatefrompng($imagePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($imagePath),
        };

        if ($src === false || $src === null) {
            return response()->json(['error' => 'Gagal membaca file gambar.'], 422);
        }

        $dst = @imagecreatetruecolor($newWidth, $newHeight);
        if ($dst === false) {
            return response()->json(['error' => 'Gagal memproses gambar (memori tidak cukup).'], 500);
        }

        if (! @imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
            return response()->json(['error' => 'Gagal mengubah ukuran gambar.'], 500);
        }

        ob_start();
        $ok = @imagejpeg($dst, null, 80);
        $compressedImageBinary = ob_get_clean();

        if (! $ok || ! is_string($compressedImageBinary) || $compressedImageBinary === '') {
            $compressedImageBinary = false;
            return response()->json(['error' => 'Gagal mengompresi gambar.'], 500);
        }
    } finally {
        if ($src instanceof \GdImage || is_resource($src)) {
            @imagedestroy($src);
        }
        if ($dst instanceof \GdImage || is_resource($dst)) {
            @imagedestroy($dst);
        }
        // Bersihkan hanya buffer yang dibuka di blok ini, jangan sentuh buffer luar Laravel.
        while (ob_get_level() > $obLevel) {
            if (@ob_end_clean() === false) {
                break;
            }
        }
    }

    if (! is_string($compressedImageBinary) || $compressedImageBinary === '') {
        return response()->json(['error' => 'Gagal mengompresi gambar.'], 500);
    }

    $base64Image = base64_encode($compressedImageBinary);
    
    $apiKey = config('services.gemini.key'); 
    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key={$apiKey}";
    
    $prompt = "Kamu adalah sistem pemindai formulir inventaris cerdas. " .
              "Baca seluruh baris tulisan tangan pada formulir ini yang terdiri dari: Tanggal, Nama Peminjam, Nama Barang, dan Jumlah. " .
              "Jika ada baris di bawahnya yang menggunakan tanda panen atau dikosongkan (mengikuti baris atasnya), ekstrak tanggal/data yang sesuai. " .
              "Koreksi typo atau singkatan pada nama barang. " .
              "Kembalikan data dalam format JSON murni tanpa markdown dengan struktur persis seperti ini: " .
              "{\"rows\": [{\"date\": \"...\", \"borrowerName\": \"...\", \"itemName\": \"...\", \"quantityTaken\": \"...\"}]}";

    try {
        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $base64Image]]
                    ]
                ]
            ]
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 

        $result = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            return response()->json(['error' => 'Koneksi Gagal: ' . $error_msg], 500);
        }
        curl_close($ch);

        $jsonResponse = json_decode($result, true);

        if (isset($jsonResponse['error'])) {
            return response()->json(['error' => 'Gemini API Error: ' . $jsonResponse['error']['message']], 500);
        }

        $text = $jsonResponse['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = trim(str_replace(['```json', '```'], '', $text));
        $parsed = json_decode($text, true);

        return response()->json(['rows' => $parsed['rows'] ?? []]);
    } catch (\Throwable $e) {
        return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
    }
})->middleware('throttle:6,1');

// 2. Rute untuk Menyimpan Data ke Google Sheets (Disesuaikan dengan Scan Timestamp di Kolom E)
Route::post('/api/save', function (Request $request) {
    if (function_exists('ob_clean')) { ob_clean(); } 
    
    $request->validate([
        'sheetId' => 'required|string|max:128',
        'sheetName' => 'required|string|max:100',
        'rows' => 'required|array|max:100',
        'rows.*.date' => 'nullable|string|max:50',
        'rows.*.borrowerName' => 'nullable|string|max:100',
        'rows.*.itemName' => 'nullable|string|max:200',
        'rows.*.quantityTaken' => 'nullable|string|max:50',
    ]);

    $credentialsPath = base_path('credentials.json');

    $credentialsJson = config('services.google.credentials_json');

    try {
        $client = new \Google_Client();
        $client->addScope(\Google_Service_Sheets::SPREADSHEETS);

        if (! empty($credentialsJson)) {
            // Prioritas: env var (wajib di hosting). Mendukung JSON mentah atau base64.
            $decoded = json_decode($credentialsJson, true);
            if (! is_array($decoded)) {
                $maybeBase64 = base64_decode($credentialsJson, true);
                if (is_string($maybeBase64) && $maybeBase64 !== '') {
                    $decoded = json_decode($maybeBase64, true);
                }
            }
            if (! is_array($decoded)) {
                return response()->json(['error' => 'Konfigurasi GOOGLE_CREDENTIALS_JSON tidak valid.'], 500);
            }
            $client->setAuthConfig($decoded);
        } else {
            // Fallback lokal: file credentials.json (jangan commit file aslinya).
            if (!file_exists($credentialsPath)) {
                return response()->json(['error' => 'Kredensial Google belum dikonfigurasi.'], 500);
            }
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
            $client->useApplicationDefaultCredentials();
        }

        $service = new \Google_Service_Sheets($client);
        
        // Menghasilkan waktu timestamp saat data disimpan
        $scanTimestamp = now()->toIso8601String();

        $values = array_map(function($row) use ($scanTimestamp) {
            return [
                $row['date'] ?? '',          
                $row['borrowerName'] ?? '',   
                $row['itemName'] ?? '',      
                $row['quantityTaken'] ?? '', 
                $scanTimestamp               
            ];
        }, $request->rows);

        $body = new \Google_Service_Sheets_ValueRange(['values' => $values]);
        
        $service->spreadsheets_values->append(
            $request->sheetId, 
            $request->sheetName . '!A:E', 
            $body, 
            ['valueInputOption' => 'USER_ENTERED']
        );

        return response()->json(['success' => true, 'message' => 'Data berhasil disimpan ke Google Sheets!']);
    } catch (\Throwable $e) {
        return response()->json(['error' => 'Gagal Sheets: ' . $e->getMessage()], 500);
    }
})->middleware('throttle:30,1');
