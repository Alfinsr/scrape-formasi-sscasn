<?php


echo "SSCASN DATA SCRAPING - 2024\n";
echo "Author Alfin SR\n\n";


echo "Masukan Kode pendidikan anda: ";
$kodeRefPend = trim(fgets(STDIN));
echo "Masukan Nama Jurusan anda: ";
$namaJurusan = trim(fgets(STDIN));


if (empty($kodeRefPend) || empty($namaJurusan)) {
    die("Kode pendidikan dan Nama jurusan tidak boleh kosong.\n");
}


define('BASE_URL', 'https://api-sscasn.bkn.go.id/2024/portal/spf');
define('KODE_REF_PEND', $kodeRefPend);
define('NAMA_JURUSAN', $namaJurusan);


$headers = [
    'accept: application/json, text/plain, */*',
    'accept-encoding: gzip, deflate, br, zstd',
    'accept-language: en-US,en;q=0.9,id-ID;q=0.8,id;q=0.7',
    'connection: keep-alive',
    'host: api-sscasn.bkn.go.id',
    'origin: https://sscasn.bkn.go.id',
    'referer: https://sscasn.bkn.go.id/',
    'sec-ch-ua: "Not)A;Brand";v="99", "Google Chrome";v="127", "Chromium";v="127"',
    'sec-ch-ua-mobile: ?1',
    'sec-ch-ua-platform: "Android"',
    'sec-fetch-dest: empty',
    'sec-fetch-mode: cors',
    'sec-fetch-site: same-site',
    'user-agent: Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Mobile Safari/537.36'
];


function setNamaJurusan($namaJurusan)
{
    return str_replace(' ', '_', $namaJurusan);
}

function fetchData($offset, $retries, $delay)
{
    global $headers;

    $url = BASE_URL . "?kode_ref_pend=" . KODE_REF_PEND . "&offset=" . $offset;

    for ($i = 0; $i < $retries; $i++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        } elseif ($httpCode == 504) {
            echo "Request failed at offset $offset: 504 Gateway Timeout. Retry " . ($i + 1) . "/$retries...\n";
            sleep($delay);
        } else {
            echo "Request failed at offset $offset: $httpCode\n";
            return null;
        }
    }

    return null;
}


function locationFilter($lokasi, $filterLokasi)
{
    return strpos(strtolower($lokasi), strtolower($filterLokasi)) !== false;
}


$options = getopt("", ["provinsi:"]);
$filterLokasi = isset($options['provinsi']) ? $options['provinsi'] : '';

echo "Proses Collecting Data...\n";


$initialData = fetchData(0, 3, 5);
if (!$initialData) {
    die("Gagal mengambil data awal\n");
}

$totalData = $initialData['data']['meta']['total'];
echo "Total data ditemukan: $totalData\n";

$timestamp = date("Ymd_His");
$dataDir = "data";
$excelOutputFile = $dataDir . "/dataFormasi_" . setNamaJurusan(NAMA_JURUSAN) . ".xlsx";


if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$filteredData = [];

$loadingSymbols = ['|', '/', '-', '\\'];
$symbolCount = count($loadingSymbols);

for ($offset = 0; $offset < $totalData; $offset += 10) {
    $symbolIndex = $offset / 10 % $symbolCount;
    $symbol = $loadingSymbols[$symbolIndex];
    echo "\r($symbol) Mengambil data ke - $offset...";

    $data = fetchData($offset, 3, 5);
    if (!$data) {
        echo "\nError fetching data at offset $offset\n";
        continue;
    }

    foreach ($data['data']['data'] as $record) {
        if ($filterLokasi && locationFilter($record['lokasi_nm'], $filterLokasi)) {
            $filteredData[] = $record;
        } else {
            $filteredData[] = $record;
        }
    }
}


echo "\rBerhasil diambil sebanyak $totalData data.\n";


require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();


$headers = ["Instansi", "Jabatan", "Formasi", "Kuota", "Gaji Min", "Gaji Max", "Pengumuman", "Lokasi"];
foreach ($headers as $key => $header) {
    $columnLetter = chr(65 + $key);
    $sheet->setCellValue($columnLetter . '4', $header);
}




foreach ($filteredData as $i => $record) {
    $sheet->setCellValue('A' . ($i + 5), isset($record['ins_nm']) ? $record['ins_nm'] : '');
    $sheet->setCellValue('B' . ($i + 5), isset($record['jabatan_nm']) ? $record['jabatan_nm'] : '');
    $sheet->setCellValue('C' . ($i + 5), isset($record['formasi_nm']) ? $record['formasi_nm'] : '');
    $sheet->setCellValue('D' . ($i + 5), isset($record['jumlah_formasi']) ? $record['jumlah_formasi'] : '');
    $sheet->setCellValue('E' . ($i + 5), isset($record['gaji_min']) ? $record['gaji_min'] : '');
    $sheet->setCellValue('F' . ($i + 5), isset($record['gaji_max']) ? $record['gaji_max'] : '');
    $sheet->setCellValue('G' . ($i + 5), isset($record['formasi_id']) ? "https://sscasn.bkn.go.id/detailformasi/" . $record['formasi_id'] : '');
    $sheet->setCellValue('H' . ($i + 5), isset($record['lokasi_nm']) ? $record['lokasi_nm'] : '');
}


$sheet->getStyle('E5:E' . ($i + 4))->getNumberFormat()->setFormatCode('Rp #,##0');
$sheet->getStyle('F5:F' . ($i + 4))->getNumberFormat()->setFormatCode('Rp #,##0');


foreach (range('A', 'H') as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

$writer = new Xlsx($spreadsheet);
$writer->save($excelOutputFile);


echo "\033[32mProses selesai! Data berhasil disimpan dalam file $excelOutputFile\033[0m\n";
