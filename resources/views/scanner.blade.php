<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handwriting Inventory Scanner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 min-h-screen text-gray-800 p-4 md:p-10">

    <div class="max-w-4xl mx-auto space-y-6">
        <header>
            <h1 class="text-3xl font-bold tracking-tight text-blue-900">Inventory Scanner</h1>
            <p class="text-gray-500">Foto form pengambilan tulisan tangan, AI akan membacanya termasuk nama peminjam, dan simpan ke Google Sheets.</p>
        </header>

        <div id="statusAlert" class="hidden p-4 rounded-md text-sm"></div>

        <!-- 1. Pengaturan Sheets -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <h2 class="text-lg font-semibold mb-4">1. Google Sheets</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Sheet ID</label>
                    <input type="text" id="sheetId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Contoh: 1XyZ_abc123...">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tab Name (Nama Sheet)</label>
                    <input type="text" id="sheetName" value="Sheet1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
        </div>

        <!-- 2. Kamera & Upload -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 space-y-4">
            <h2 class="text-lg font-semibold">2. Upload Dokumen</h2>
            <input type="file" id="imageInput" accept="image/*" capture="environment" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            
            <div id="previewContainer" class="hidden mt-4 space-y-4">
                <img id="imagePreview" class="max-h-80 w-full object-contain rounded border bg-gray-50">
                <button id="btnScan" class="w-full bg-blue-600 text-white py-2 px-4 rounded-md font-medium hover:bg-blue-700 transition">
                    Mulai Deteksi AI
                </button>
            </div>
        </div>

        <!-- 3. Review & Edit -->
        <div id="reviewSection" class="hidden bg-white p-6 rounded-xl shadow-sm border border-gray-200 space-y-4">
            <h2 class="text-lg font-semibold">3. Review Hasil Deteksi AI</h2>
            <p class="text-sm text-gray-500">Cek kembali data pengambil dan barang sebelum disimpan ke Sheets.</p>
            
            <div id="rowsContainer" class="space-y-4"></div>
            
            <button id="btnSave" class="w-full bg-green-600 text-white py-2 px-4 rounded-md font-medium hover:bg-green-700 transition shadow-md">
                Simpan ke Google Sheets
            </button>
        </div>
    </div>

    <script>
        const imageInput = document.getElementById('imageInput');
        const imagePreview = document.getElementById('imagePreview');
        const previewContainer = document.getElementById('previewContainer');
        const btnScan = document.getElementById('btnScan');
        const reviewSection = document.getElementById('reviewSection');
        const rowsContainer = document.getElementById('rowsContainer');
        const btnSave = document.getElementById('btnSave');
        const statusAlert = document.getElementById('statusAlert');
        
        const inputSheetId = document.getElementById('sheetId');
        const inputSheetName = document.getElementById('sheetName');
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        let imageFile = null;

        // 1. FITUR AUTO-LOAD: Memanggil data link terakhir saat halaman dimuat
        document.addEventListener('DOMContentLoaded', () => {
            const savedLink = localStorage.getItem('savedSheetLink');
            const savedTab = localStorage.getItem('savedSheetTab');
            
            if (savedLink) inputSheetId.value = savedLink;
            if (savedTab) inputSheetName.value = savedTab;
            
            // Update placeholder agar lebih informatif
            inputSheetId.placeholder = "Paste Link Google Sheets di sini...";
        });

        function showAlert(message, isError = true) {
            statusAlert.innerText = message;
            statusAlert.className = `p-4 rounded-md text-sm ${isError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200'}`;
            statusAlert.classList.remove('hidden');
        }

        async function compressImage(file, maxWidth = 1200) {
            return new Promise((resolve, reject) => {
                if (!file) {
                    reject(new Error('Tidak ada file gambar yang dipilih.'));
                    return;
                }
                const MAX_BYTES = 12 * 1024 * 1024;
                if (file.size > MAX_BYTES) {
                    reject(new Error('Ukuran file terlalu besar. Maksimal 12MB.'));
                    return;
                }
                const reader = new FileReader();
                reader.onerror = () => reject(new Error('Gagal membaca file gambar.'));
                reader.readAsDataURL(file);
                reader.onload = (event) => {
                    const img = new Image();
                    img.onerror = () => reject(new Error('File gambar rusak atau tidak didukung.'));
                    img.src = event.target.result;
                    img.onload = () => {
                        try {
                            if (img.width <= maxWidth) return resolve(file);
                            const canvas = document.createElement('canvas');
                            const scaleSize = maxWidth / img.width;
                            canvas.width = maxWidth;
                            canvas.height = Math.round(img.height * scaleSize);
                            const ctx = canvas.getContext('2d');
                            if (!ctx) return resolve(file);
                            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                            canvas.toBlob((blob) => {
                                if (!blob) {
                                    reject(new Error('Gagal mengompresi gambar.'));
                                    return;
                                }
                                resolve(new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() }));
                            }, 'image/jpeg', 0.8);
                        } catch (err) {
                            reject(err instanceof Error ? err : new Error('Gagal memproses gambar.'));
                        }
                    };
                };
            });
        }

        imageInput.addEventListener('change', (e) => {
            imageFile = e.target.files[0];
            statusAlert.classList.add('hidden');
            if (imageFile) {
                const reader = new FileReader();
                reader.onerror = () => showAlert('Gagal membaca file gambar.');
                reader.onload = (e) => {
                    imagePreview.onerror = () => showAlert('Preview gambar gagal dimuat. File mungkin korup.');
                    imagePreview.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                    reviewSection.classList.add('hidden');
                };
                reader.readAsDataURL(imageFile);
            }
        });

        btnScan.addEventListener('click', async () => {
            statusAlert.classList.add('hidden');
            if (!imageFile) {
                showAlert('Pilih file gambar terlebih dahulu.');
                return;
            }
            const originalText = btnScan.innerText;
            btnScan.disabled = true;

            try {
                btnScan.innerText = "⏳ Memperkecil ukuran foto...";
                const compressedFile = await compressImage(imageFile, 1200);
                const formData = new FormData();
                formData.append('image', compressedFile);

                btnScan.innerText = "⏳ AI sedang membaca...";
                const response = await fetch('/api/scan', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData
                });
                
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) throw new Error("Respons server salah (bukan JSON). Coba periksa terminal server.");

                const result = await response.json();
                if(!response.ok) throw new Error(result.error || 'Terjadi kesalahan sistem');

                renderReviewRows(result.rows);
                reviewSection.classList.remove('hidden');
            } catch (error) {
                showAlert(error.message === 'Failed to fetch' ? 'Koneksi terputus! Pastikan server terminal masih menyala.' : error.message);
            } finally {
                btnScan.innerText = originalText;
                btnScan.disabled = false;
            }
        });

        function createReviewField(labelText, value, inputClass) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'text-xs text-gray-500 font-semibold uppercase';
            label.textContent = labelText;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = inputClass + ' w-full border border-gray-300 rounded p-2';
            input.value = value ?? '';
            wrapper.appendChild(label);
            wrapper.appendChild(input);
            return wrapper;
        }

        function renderReviewRows(rows) {
            rowsContainer.innerHTML = '';
            if(!rows || rows.length === 0) {
                const emptyMsg = document.createElement('p');
                emptyMsg.className = 'text-red-500 text-sm';
                emptyMsg.textContent = 'Tidak ada data yang terbaca dari gambar.';
                rowsContainer.appendChild(emptyMsg);
                return;
            }
            rows.forEach((row) => {
                const card = document.createElement('div');
                card.className = 'border rounded-md p-4 bg-gray-50 grid grid-cols-1 md:grid-cols-4 gap-4 row-item';
                card.appendChild(createReviewField('Tanggal', row.date || '', 'input-date'));
                card.appendChild(createReviewField('Nama Pengambil', row.borrowerName || '', 'input-borrower'));
                card.appendChild(createReviewField('Nama Barang', row.itemName || '', 'input-item'));
                card.appendChild(createReviewField('Jumlah', row.quantityTaken || '', 'input-qty'));
                rowsContainer.appendChild(card);
            });
        }

        btnSave.addEventListener('click', async () => {
            let rawInput = inputSheetId.value.trim();
            const sheetName = inputSheetName.value.trim();

            if(!rawInput) return showAlert('Tolong masukkan Link / Sheet ID terlebih dahulu!');

            // 2. FITUR AUTO-SAVE: Menyimpan inputan terakhir ke browser
            localStorage.setItem('savedSheetLink', rawInput);
            localStorage.setItem('savedSheetTab', sheetName);

            // 3. FITUR AUTO-EKSTRAK: Cek apakah itu link URL panjang? Kalau ya, ambil ID-nya saja!
            let finalSheetId = rawInput;
            const match = rawInput.match(/\/d\/([a-zA-Z0-9-_]+)/);
            if (match && match[1]) {
                finalSheetId = match[1]; // Mengambil kode panjang di tengah link
            }

            const originalText = btnSave.innerText;
            btnSave.innerText = "⏳ Menyimpan...";
            btnSave.disabled = true;

            const rowsData = [];
            document.querySelectorAll('.row-item').forEach(el => {
                rowsData.push({
                    date: el.querySelector('.input-date').value,
                    borrowerName: el.querySelector('.input-borrower').value,
                    itemName: el.querySelector('.input-item').value,
                    quantityTaken: el.querySelector('.input-qty').value
                });
            });

            try {
                const response = await fetch('/api/save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ sheetId: finalSheetId, sheetName, rows: rowsData }) // Mengirim ID yang sudah bersih
                });

                const result = await response.json();
                if(!response.ok) {
                    let errMsg = result.error || result.message || 'Gagal menyimpan';
                    throw new Error('Error Server: ' + errMsg);
                }

                showAlert("Sukses! " + result.message, false);
                reviewSection.classList.add('hidden');
                previewContainer.classList.add('hidden');
                imageInput.value = '';
            } catch (error) {
                showAlert(error.message);
            } finally {
                btnSave.innerText = originalText;
                btnSave.disabled = false;
            }
        });
    </script>
</body>
</html>