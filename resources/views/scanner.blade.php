<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Scanner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { -webkit-font-smoothing: antialiased; }
        button:disabled { opacity: .65; cursor: not-allowed; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 9999px; animation: spin .7s linear infinite; vertical-align: -2px; margin-right: .5rem; }
        input[type="file"]::file-selector-button { cursor: pointer; }
    </style>
</head>
<body class="bg-gradient-to-b from-slate-100 via-blue-50 to-slate-100 min-h-screen text-slate-800">

    <div class="max-w-5xl mx-auto px-4 md:px-8 py-6 md:py-10 space-y-6">

        <!-- Header -->
        <header class="bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-600 rounded-2xl shadow-lg text-white p-6 md:p-8">
            <div class="flex flex-col md:flex-row md:items-center gap-4 md:gap-6">
                <div class="shrink-0 w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center text-2xl font-bold">IS</div>
                <div class="flex-1">
                    <h1 class="text-2xl md:text-3xl font-bold tracking-tight">Inventory Scanner</h1>
                    <p class="text-blue-100 text-sm md:text-base mt-1">Foto form pengambilan tulisan tangan, AI membacanya, lalu simpan ke Google Sheets.</p>
                </div>
            </div>
            <ol class="flex flex-wrap gap-2 mt-5 text-xs md:text-sm font-medium">
                <li class="bg-white/15 rounded-full px-3 py-1.5"><span class="font-bold mr-1">1</span> Hubungkan Sheets</li>
                <li class="bg-white/15 rounded-full px-3 py-1.5"><span class="font-bold mr-1">2</span> Upload Dokumen</li>
                <li class="bg-white/15 rounded-full px-3 py-1.5"><span class="font-bold mr-1">3</span> Review &amp; Simpan</li>
            </ol>
        </header>

        <div id="statusAlert" class="hidden p-4 rounded-xl text-sm shadow-sm" role="alert"></div>

        <!-- 1. Pengaturan Sheets -->
        <section class="bg-white p-6 md:p-7 rounded-2xl shadow-sm border border-slate-200">
            <div class="flex items-center gap-3 mb-5">
                <span class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 font-bold flex items-center justify-center text-sm">1</span>
                <h2 class="text-lg font-semibold">Google Sheets</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="sheetId" class="block text-sm font-medium text-slate-700 mb-1.5">Sheet ID / Link</label>
                    <input type="text" id="sheetId" class="block w-full rounded-lg border-slate-300 shadow-sm border p-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition" placeholder="Paste Link Google Sheets di sini...">
                    <p class="text-xs text-slate-400 mt-1.5">Bisa berupa link panjang atau ID saja. Tersimpan otomatis di browser.</p>
                </div>
                <div>
                    <label for="sheetName" class="block text-sm font-medium text-slate-700 mb-1.5">Tab Name (Nama Sheet)</label>
                    <input type="text" id="sheetName" value="Sheet1" class="block w-full rounded-lg border-slate-300 shadow-sm border p-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                </div>
            </div>
        </section>

        <!-- 2. Kamera & Upload -->
        <section class="bg-white p-6 md:p-7 rounded-2xl shadow-sm border border-slate-200 space-y-4">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 font-bold flex items-center justify-center text-sm">2</span>
                <h2 class="text-lg font-semibold">Upload Dokumen</h2>
            </div>
            <label for="imageInput" class="block border-2 border-dashed border-slate-300 rounded-xl p-6 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/50 transition">
                <span class="block text-sm font-medium text-slate-600">Klik untuk pilih foto form, atau seret file ke sini</span>
                <span class="block text-xs text-slate-400 mt-1">JPEG / PNG / WebP, maksimal 12MB</span>
            </label>
            <input type="file" id="imageInput" accept="image/*" capture="environment" class="sr-only">

            <div id="previewContainer" class="hidden mt-2 space-y-4">
                <img id="imagePreview" alt="Pratinjau dokumen yang diupload" class="max-h-80 w-full object-contain rounded-xl border border-slate-200 bg-slate-50 shadow-inner">
                <button id="btnScan" class="w-full bg-blue-600 text-white py-3 px-4 rounded-xl font-semibold hover:bg-blue-700 active:bg-blue-800 transition shadow-md shadow-blue-200">
                    Mulai Deteksi AI
                </button>
            </div>
        </section>

        <!-- 3. Review & Edit -->
        <section id="reviewSection" class="hidden bg-white p-6 md:p-7 rounded-2xl shadow-sm border border-slate-200 space-y-4">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-lg bg-green-100 text-green-700 font-bold flex items-center justify-center text-sm">3</span>
                <div>
                    <h2 class="text-lg font-semibold">Review Hasil Deteksi AI</h2>
                    <p class="text-sm text-slate-500">Cek kembali data sebelum disimpan ke Sheets. Kolom masih bisa diedit.</p>
                </div>
            </div>

            <div id="rowsContainer" class="space-y-4"></div>

            <button id="btnSave" class="w-full bg-green-600 text-white py-3 px-4 rounded-xl font-semibold hover:bg-green-700 active:bg-green-800 transition shadow-md shadow-green-200">
                Simpan ke Google Sheets
            </button>
        </section>

        <footer class="text-center text-xs text-slate-400 pb-4">
            Data hanya dikirim ke AI saat tombol deteksi ditekan. Link Sheets tersimpan lokal di browser Anda.
        </footer>
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
        });

        function showAlert(message, isError = true) {
            statusAlert.innerText = message;
            statusAlert.className = `p-4 rounded-xl text-sm shadow-sm ${isError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200'}`;
            statusAlert.classList.remove('hidden');
            statusAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
                btnScan.innerHTML = '<span class="spinner"></span>Memperkecil ukuran foto...';
                const compressedFile = await compressImage(imageFile, 1200);
                const formData = new FormData();
                formData.append('image', compressedFile);

                btnScan.innerHTML = '<span class="spinner"></span>AI sedang membaca...';
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
                reviewSection.scrollIntoView({ behavior: 'smooth' });
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
            label.className = 'block text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1.5';
            label.textContent = labelText;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = inputClass + ' w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition bg-white';
            input.value = value ?? '';
            wrapper.appendChild(label);
            wrapper.appendChild(input);
            return wrapper;
        }

        function renderReviewRows(rows) {
            rowsContainer.innerHTML = '';
            if(!rows || rows.length === 0) {
                const emptyMsg = document.createElement('p');
                emptyMsg.className = 'text-red-500 text-sm bg-red-50 border border-red-200 rounded-xl p-4';
                emptyMsg.textContent = 'Tidak ada data yang terbaca dari gambar.';
                rowsContainer.appendChild(emptyMsg);
                return;
            }
            rows.forEach((row, index) => {
                const card = document.createElement('div');
                card.className = 'border border-slate-200 rounded-xl p-4 md:p-5 bg-slate-50 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 row-item shadow-sm';
                const badge = document.createElement('div');
                badge.className = 'sm:col-span-2 lg:col-span-4 text-xs font-bold text-slate-400 uppercase tracking-wider';
                badge.textContent = 'Baris ' + (index + 1);
                card.appendChild(badge);
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
            btnSave.innerHTML = '<span class="spinner"></span>Menyimpan...';
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
