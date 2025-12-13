<?php
error_reporting(E_ERROR | E_PARSE);
header('Content-Type: application/json; charset=utf-8');
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = __DIR__ . "/natiga.xlsx";
if (!file_exists($file)) {
    echo json_encode(["error" => "⚠️ ملف النتائج غير موجود"], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
try {
    $spreadsheet = IOFactory::load($file);

    if ($action === "getSheets") {
        $sheets = $spreadsheet->getSheetNames();
        echo json_encode($sheets, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === "getResult") {
        $sheetName = $_GET['sheet'] ?? '';
        $studentId = $_GET['id'] ?? '';
        if (!$sheetName || !$studentId) {
            echo json_encode(["error" => "⚠️ يرجى اختيار القائمة وإدخال كود البحث"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            echo json_encode(["error" => "⚠️ الورقة المحددة غير موجودة"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rows = $sheet->toArray();
        $headers = $rows[0] ?? [];
        $target = trim((string)$studentId);
        $found = false;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 0) continue; // تخطي صف العناوين
            $cellId = trim((string)$row[0]);
            if ($cellId === $target) {
                $output = [];
                for ($i = 0; $i < count($headers); $i++) {
                    $header = trim((string)$headers[$i]);
                    $value  = isset($row[$i]) ? trim((string)$row[$i]) : "";

                    if ($i === 0) {
                        // العمود الأول رقم الطالب
                        $output[] = ["type" => "grade", "header" => $header, "value" => $value];
                    } else {
                        if ($value === "") {
                            // خلية فارغة → نعتبرها عنوان قسم
                            $output[] = ["type" => "section", "header" => $header];
                        } else {
                            // أي محتوى (رقم أو نص) → قيمة
                            $output[] = ["type" => "grade", "header" => $header, "value" => $value];
                        }
                    }
                }

                echo json_encode($output, JSON_UNESCAPED_UNICODE);
                $found = true;
                break;
            }
        }

        if (!$found) {
            echo json_encode(["error" => "لم يتم العثور على بيانات لهذا الرقم"], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    echo json_encode(["error" => "⚠️ طلب غير معروف"], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(["error" => "⚠️ خطأ داخلي: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
