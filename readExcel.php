<?php
/**
 * Project: Student Results System (Excel2Web)
 * Author: Ali Al-Khader (https://alielkhedr.com)
 * License: MIT
 */
session_start(); // بدء الجلسة لتتبع الطلبات
error_reporting(E_ERROR | E_PARSE);
header('Content-Type: application/json; charset=utf-8');

// --- إعدادات الأمان (Security Checks) ---

// 1. التحقق من مصدر الطلب (Referer Check) لمنع الوصول المباشر
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (empty($referer) || stripos($referer, $_SERVER['HTTP_HOST']) === false) {
    http_response_code(403); // Forbidden
    echo json_encode(["error" => "⚠️ وصول غير مصرح به (Direct Access Forbidden)"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. منع الإغراق بالطلبات (Rate Limiting) - طلب واحد كحد أقصى كل ثانية
if (isset($_SESSION['last_req_time']) && (time() - $_SESSION['last_req_time'] < 1)) {
    http_response_code(429); // Too Many Requests
    echo json_encode(["error" => "⚠️ يرجى الانتظار قليلاً قبل المحاولة مرة أخرى"], JSON_UNESCAPED_UNICODE);
    exit;
}
$_SESSION['last_req_time'] = time();
// --- نهاية إعدادات الأمان ---

$file = __DIR__ . "/natiga.xlsx";
$cacheFile = __DIR__ . "/natiga_cache.json";
$useCache = file_exists($cacheFile);

// تحميل مكتبة PhpSpreadsheet فقط عند الحاجة (إذا لم يوجد cache)
if (!$useCache) {
    require 'vendor/autoload.php';
}

if (!file_exists($file) && !$useCache) {
    echo json_encode(["error" => "⚠️ ملف النتائج غير موجود"], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    // --- الوضع السريع: قراءة من JSON Cache ---
    if ($useCache) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        
        if ($action === "getSheets") {
            echo json_encode(array_keys($cacheData), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === "getResult") {
            $sheetName = $_GET['sheet'] ?? '';
            $studentId = $_GET['id'] ?? '';
            if (!$sheetName || !$studentId) {
                echo json_encode(["error" => "⚠️ يرجى اختيار القائمة وإدخال كود البحث"], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!isset($cacheData[$sheetName])) {
                echo json_encode(["error" => "⚠️ الورقة المحددة غير موجودة"], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $rows = $cacheData[$sheetName];
            $headers = $rows[0] ?? [];
            $target = trim((string)$studentId);
            $found = false;

            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex === 0) continue;
                $cellId = trim((string)($row[0] ?? ''));
                if ($cellId === $target) {
                    $output = [];
                    for ($i = 0; $i < count($headers); $i++) {
                        $header = trim((string)($headers[$i] ?? ''));
                        $value  = isset($row[$i]) ? trim((string)$row[$i]) : "";

                        if ($i === 0) {
                            $output[] = ["type" => "grade", "header" => $header, "value" => $value];
                        } else {
                            if ($value === "") {
                                $output[] = ["type" => "section", "header" => $header];
                            } else {
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
        exit;
    }

    // --- الوضع الاحتياطي: قراءة من Excel مباشرة (إذا لم يوجد Cache) ---
    ini_set('memory_limit', '1536M');

    // مرشّح لتقييد نطاق القراءة (يمنع استهلاك الذاكرة إذا كان نطاق الملف الفعلي فاسداً/متضخماً)
    if (!class_exists('LimitedRangeReadFilter')) {
        class LimitedRangeReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            const MAX_ROW = 3000;
            const MAX_COL_INDEX = 60; // يعادل العمود BH تقريباً
            public function readCell($columnAddress, $row, $worksheetName = '') {
                if ($row > self::MAX_ROW) return false;
                $colIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress);
                return $colIndex <= self::MAX_COL_INDEX;
            }
        }
    }

    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setReadDataOnly(true); // تجاهل التنسيقات لتقليل استهلاك الذاكرة
    $reader->setReadFilter(new LimitedRangeReadFilter());
    $spreadsheet = $reader->load($file);

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
