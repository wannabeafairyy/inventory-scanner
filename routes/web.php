<?php

ini_set('display_errors', '0');
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '20M');
ini_set('max_execution_time', '0');

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/scanner', function () {
    return view('scanner'); 
});

// 1. Rute untuk Memindai Gambar dengan AI (Raw cURL murni)
Route::post('/api/scan', function (Request $request) {
    if (function_exists('ob_clean')) { ob_clean(); }
    ini_set('display_errors', 0);
    $request->validate(['image' => 'required|image|max:12288']);
    
    $image = $request->file('image');
    $imagePath = $image->getRealPath();
    
    // --- KOMPRESI & RESIZE OTOMATIS SUPAYA TIDAK TIMEOUT ---
    list($width, $height, $type) = getimagesize($imagePath);
    $maxDim = 1280; // Batasi sisi maksimal 1280px agar ringan dikirim
    
    if ($width > $maxDim || $height > $maxDim) {
        $ratio = $width / $height;
        if ($ratio > 1) {
            $newWidth = $maxDim;
            $newHeight = $maxDim / $ratio;
        } else {
            $newHeight = $maxDim;
            $newWidth = $maxDim * $ratio;
        }
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($imagePath),
        IMAGETYPE_PNG => imagecreatefrompng($imagePath),
        IMAGETYPE_WEBP => imagecreatefromwebp($imagePath),
        default => imagecreatefromjpeg($imagePath)
    };

    $dst = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Simpan sementara gambar yang sudah dikecilkan ukurannya ke memory buffer
    ob_start();
    imagejpeg($dst, null, 80); // Kualitas 80% (sangat cukup untuk dibaca AI & cepat)
    $compressedImageBinary = ob_get_clean();
    
    imagedestroy($src);
    imagedestroy($dst);

    $base64Image = base64_encode($compressedImageBinary);
    // --------------------------------------------------------
    
    $apiKey = env('GEMINI_API_KEY'); 
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
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
});

// 2. Rute untuk Menyimpan Data ke Google Sheets (Disesuaikan dengan Scan Timestamp di Kolom E)
Route::post('/api/save', function (Request $request) {
    if (function_exists('ob_clean')) { ob_clean(); } 
    
    $request->validate([
        'sheetId' => 'required', 
        'sheetName' => 'required', 
        'rows' => 'required|array'
    ]);

    $credentialsPath = base_path('credentials.json'); 
    
    if (!file_exists($credentialsPath)) {
        return response()->json(['error' => 'File credentials.json tidak ditemukan di root folder!'], 500);
    }

    try {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
        $client = new \Google_Client();
        $client->useApplicationDefaultCredentials();
        $client->addScope(\Google_Service_Sheets::SPREADSHEETS);

        $service = new \Google_Service_Sheets($client);
        
        // Menghasilkan waktu timestamp saat data disimpan
        $scanTimestamp = now()->toIso8601String();

        $values = array_map(function($row) use ($scanTimestamp) {
            return [
                $row['date'] ?? '',          // Kolom A: Date
                $row['borrowerName'] ?? '',  // Kolom B: Borrower Name
                $row['itemName'] ?? '',      // Kolom C: Item Name
                $row['quantityTaken'] ?? '', // Kolom D: Quantity Taken
                $scanTimestamp               // Kolom E: Scan Timestamp Otomatis
            ];
        }, $request->rows);

        $body = new \Google_Service_Sheets_ValueRange(['values' => $values]);
        
        // Diubah ke A:E karena sekarang mencakup 5 kolom
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
});