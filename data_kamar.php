<?php

function getKamarList(): array
{
    // Gambar dummy untuk ditampilkan di kartu kamar
    $images = [
        'https://lh3.googleusercontent.com/aida-public/AB6AXuAdFUQGy5O8wafsrNaqbPKcAXngRR8ERkhSx146sD6jRqJJ_yZ857CVKy-CqR29hU6tg6S_HNEtro6F_nb90mrp_T91UEwuoJGKeui1FOC9LE46JCB8synuoOhCzxzGRnOS8Q6WXOlbGnUecZQ00IB8mNp4UbOSUYqUCNmOrbaWiLyOcan1ySWVFEqtTEt-OBokzCPetcuQfKSlE6QsrYlGS1qSYSf8P8G_-WudhFym8icJuoLORLX37AaQdS4nmk_rM3vmUeGuogCz',
        'https://lh3.googleusercontent.com/aida-public/AB6AXuC7C4IsvgXAAUJSimUWv0hQxYrdo9r0YFWLijPjDfwHv4L3L1kXkdeHPhc_Z9SfzYsVDhu9sVNInJu2aIe5ebZTVPmO60VemQsGfYMPg_STVhEEKtbtJPOlJ66SaB9_074NP7A5AJRgUbv6jPW7yxchdbmQ2F6DVXhURqe-y7qCLI6erWeYvTbiuzzAiYnMaPMWqiWFIjdDrrI2ZGIMrMC43oTDPDUY5kSOfb2KIDdw8-ZnLGNeScc9kn4Hf2prxGlJfm98-valiJ9B',
        'https://lh3.googleusercontent.com/aida-public/AB6AXuAuxbTBz2YnxENVpzIHuGC1kC4LsqOFx3NddDShf3Q-09R3Z7BJjrd3hv4CUJvNpxOzTavnXQY2XRRAROVs1_63CZdJQXILoK98-iH3q9OspOfzZjKWGSZ7pIpmlGi_B_9gZxBrt0DkzoxgpNRD8c0y9zwgwX2YLCdwk1W67hRCPcoIbFz6-Qnuu-M8i-7QuPaK0XTTZtPMZLnWisaF9r3jlzkD8kAvGS3pLAofjMZcrkL6ekQaSB2TV_o6-iziOh50STtar7ad3KVt',
        'https://lh3.googleusercontent.com/aida-public/AB6AXuBPKLqIRCcLIQ8-ewRkUya_sHCCh-xJLJ0WQozgL9Vw7s0uTU2pnScfLng47IhbR4NRfL0Sdxe670TCpG5diK_beJ2qAS7KXmkf2AADVdl46JlhY_fsnpMqMuXJ--Vm8WwPl5xQLD362_N521iiVQYQOs5FUzatZrMJyHa51JTLS2HHxUy932auPu4TtXmiD8bOn2d7XFtEg_V8zHSGjOG6UZ35eAV6OiCJJSDhQkJCdmWUKYpA8Zr5Cga44KTajECwAplvBIMvQy6A',
    ];

    // Peta lokasi berdasarkan tipe kamar (untuk tampilan kartu)
    $lokasi_map = [
        'Putra'  => 'Tebet, Jakarta Selatan',
        'Putri'  => 'Setiabudi, Jakarta Selatan',
        'Campur' => 'Kuningan, Jakarta Selatan',
    ];

    // Coba ambil dari database nyata dengan JOIN ke tabel pemilik
    try {
        $config_path = __DIR__ . '/config/koneksi.php';
        if (file_exists($config_path)) {
            // Buat koneksi PDO sendiri; hindari memanggil $pdo global
            $dsn  = 'mysql:host=127.0.0.1;dbname=kelolakos;charset=utf8mb4';
            $conn = new PDO($dsn, 'root', '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $stmt = $conn->query(
                "SELECT k.id, k.nomor_kamar, k.tipe_kamar, k.harga_sewa, k.fasilitas, k.alamat, k.status,
                        COALESCE(p.full_name, 'Pemilik Kos KelolaKos') AS owner_name
                 FROM kamar k
                 LEFT JOIN pemilik p ON p.id = k.owner_id
                 ORDER BY k.id ASC
                 LIMIT 8"
            );
            $rows = $stmt->fetchAll();

            if (!empty($rows)) {
                $result = [];
                foreach ($rows as $idx => $row) {
                    $harga = (float)($row['harga_sewa'] ?? 0);
                    if ($harga >= 1_000_000) {
                        $hargaFormatted = 'Rp ' . rtrim(rtrim(number_format($harga / 1_000_000, 2, ',', '.'), '0'), ',') . ' jt';
                    } else {
                        $hargaFormatted = 'Rp ' . number_format($harga, 0, ',', '.');
                    }

                    $tipe    = $row['tipe_kamar'] ?? 'Campur';
                    $lokasi  = !empty($row['alamat']) ? htmlspecialchars($row['alamat'], ENT_QUOTES, 'UTF-8') : ($lokasi_map[$tipe] ?? 'Jakarta, Indonesia');
                    $fasStr  = $row['fasilitas'] ?? 'WiFi, AC';
                    $amenities = array_values(array_filter(array_map('trim', explode(',', $fasStr))));
                    if (empty($amenities)) {
                        $amenities = ['WiFi', 'AC'];
                    }

                    $result[] = [
                        'id'         => (int)$row['id'],
                        'name'       => 'Kamar ' . htmlspecialchars($row['nomor_kamar'], ENT_QUOTES, 'UTF-8'),
                        'type'       => htmlspecialchars($tipe, ENT_QUOTES, 'UTF-8'),
                        'location'   => $lokasi,
                        'price'      => $hargaFormatted,
                        'status'     => $row['status'] ?? 'Kosong',
                        'amenities'  => $amenities,
                        'image'      => $images[$idx % count($images)],
                        'owner_name' => htmlspecialchars($row['owner_name'], ENT_QUOTES, 'UTF-8'),
                    ];
                }
                return $result;
            }
        }
    } catch (Exception $e) {
        // Fallback ke data statis jika DB tidak tersedia
    }

    // ---- Data fallback statis (dipakai jika tabel kamar masih kosong / DB error) ----
    return [
        [
            'id'         => 1,
            'name'       => 'Kamar A-01',
            'type'       => 'Putra',
            'location'   => 'Tebet, Jakarta Selatan',
            'price'      => 'Rp 2,2 jt',
            'status'     => 'Kosong',
            'amenities'  => ['WiFi', 'AC', 'KM Dalam'],
            'image'      => $images[0],
            'owner_name' => 'Pemilik Kos KelolaKos',
        ],
        [
            'id'         => 2,
            'name'       => 'Kamar B-02',
            'type'       => 'Putri',
            'location'   => 'Setiabudi, Jakarta Selatan',
            'price'      => 'Rp 1,85 jt',
            'status'     => 'Kosong',
            'amenities'  => ['WiFi', 'AC'],
            'image'      => $images[1],
            'owner_name' => 'Pemilik Kos KelolaKos',
        ],
        [
            'id'         => 3,
            'name'       => 'Kamar C-102',
            'type'       => 'Campur',
            'location'   => 'Kuningan, Jakarta Selatan',
            'price'      => 'Rp 3,5 jt',
            'status'     => 'Kosong',
            'amenities'  => ['Balkon', 'AC', 'KM Dalam'],
            'image'      => $images[2],
            'owner_name' => 'Rizal Febri (Pemilik Kos)',
        ],
        [
            'id'         => 4,
            'name'       => 'Kamar D-04',
            'type'       => 'Putra',
            'location'   => 'Kemang, Jakarta Selatan',
            'price'      => 'Rp 2,75 jt',
            'status'     => 'Kosong',
            'amenities'  => ['WiFi', 'Smart TV'],
            'image'      => $images[3],
            'owner_name' => 'Rizal Febri (Pemilik Kos)',
        ],
    ];
}
