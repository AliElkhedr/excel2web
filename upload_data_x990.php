<?php
/**
 * Project: Student Results System (Excel2Web)
 * Author: Ali Al-Khader (https://alielkhedr.com)
 * License: MIT
 */
session_start();

// --- إعدادات الحماية بكلمة مرور ---
// غيّر كلمة المرور هذه إلى كلمة مرور قوية خاصة بك
$UPLOAD_PASSWORD = 'Chang3Me!2026';

$isLoggedIn = isset($_SESSION['upload_auth']) && $_SESSION['upload_auth'] === true;

// تسجيل الخروج
if (isset($_GET['logout'])) {
    unset($_SESSION['upload_auth']);
    header('Location: ' . basename(__FILE__));
    exit;
}

// معالجة تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    if ($_POST['login_password'] === $UPLOAD_PASSWORD) {
        $_SESSION['upload_auth'] = true;
        $isLoggedIn = true;
    } else {
        $loginError = "كلمة المرور غير صحيحة.";
    }
}

// توليد CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// إعدادات الصفحة
$uploadDir = __DIR__ . '/'; // حفظ في نفس المجلد الحالي ليتم قراءته بواسطة readExcel.php
$targetFileName = 'natiga.xlsx'; // الاسم الإجباري للملف
$message = '';
$messageType = ''; // success or error
$targetFilePath = $uploadDir . $targetFileName;

// معالجة الطلب عند ضغط زر الرفع (فقط إذا مسجل دخول)
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    
    // التحقق من CSRF Token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "⚠️ انتهت صلاحية الجلسة. يرجى إعادة تحميل الصفحة.";
        $messageType = 'error';
    }
    // 1. التحقق من وجود ملف
    elseif ($_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        
        $fileTmpPath = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        
        // 2. التحقق من MIME type الحقيقي
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fileTmpPath);
        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip' // xlsx هو في الأساس ملف zip
        ];
        
        if (!in_array($mimeType, $allowedMimes)) {
            $message = "⚠️ نوع الملف غير مسموح. يجب أن يكون ملف Excel (.xlsx) حقيقي.";
            $messageType = 'error';
        }
        // 3. التحقق الصارم من اسم الملف وامتداده
        elseif ($fileName === $targetFileName) {
            
            // 4. نقل الملف إلى المجلد النهائي (سيقوم باستبدال الملف القديم إن وجد)
            if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
                $message = "تم رفع الملف ($fileName) بنجاح إلى الاستضافة.";
                $messageType = 'success';
                // تجديد CSRF token بعد نجاح العملية
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                // توليد JSON Cache لتسريع القراءة
                try {
                    ini_set('memory_limit', '1536M');
                    require_once 'vendor/autoload.php';

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
                    $spreadsheet = $reader->load($targetFilePath);
                    $cacheData = [];
                    foreach ($spreadsheet->getSheetNames() as $sheetName) {
                        $sheet = $spreadsheet->getSheetByName($sheetName);
                        $cacheData[$sheetName] = $sheet->toArray();
                    }
                    file_put_contents(__DIR__ . '/natiga_cache.json', json_encode($cacheData, JSON_UNESCAPED_UNICODE));
                    $message .= " ✅ تم تحديث الذاكرة المؤقتة بنجاح.";
                } catch (Throwable $e) {
                    $message .= " ⚠️ تنبيه: فشل تحديث الذاكرة المؤقتة: " . $e->getMessage();
                }
            } else {
                $message = "حدث خطأ أثناء محاولة نقل الملف إلى المجلد.";
                $messageType = 'error';
            }
            
        } else {
            $message = "عفواً، الملف مرفوض. يجب أن يكون اسم الملف وامتداده: <strong>$targetFileName</strong> حصراً.";
            $messageType = 'error';
        }
        
    } else {
        $message = "يرجى اختيار ملف لرفعه، أو التأكد من عدم وجود أخطاء في الملف.";
        $messageType = 'error';
    }
}

// جلب معلومات الملف الحالي لعرضها
if (file_exists($targetFilePath)) {
    $lastModified = date("Y-m-d H:i:s", filemtime($targetFilePath));
    $fileInfoMsg = "آخر تحديث للملف: <span dir='ltr'>$lastModified</span>";
} else {
    $fileInfoMsg = "الملف غير موجود حالياً.";
}

// --- تحديد أقصى حجم ملف مسموح به من إعدادات الاستضافة (PHP) ---
function iniSizeToBytes($val) {
    $val = trim($val);
    if ($val === '' || $val === '-1') return PHP_INT_MAX;
    $unit = strtolower(substr($val, -1));
    $num = (float) $val;
    switch ($unit) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
        default:  return (float) $val;
    }
}
$uploadMaxBytes = iniSizeToBytes(ini_get('upload_max_filesize'));
$postMaxBytes   = iniSizeToBytes(ini_get('post_max_size'));
$effectiveMaxBytes = min($uploadMaxBytes, $postMaxBytes);
$effectiveMaxMB = round($effectiveMaxBytes / (1024 * 1024), 1);
$serverLimitsMsg = "استضافتك الحالية لا تستقبل حجم ملف أكبر من: <strong>{$effectiveMaxMB} MB</strong> (حد الذاكرة المخصص للمعالجة: <span dir='ltr'>" . ini_get('memory_limit') . "</span>)";
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رفع ملف النتيجة</title>
    <!-- Bootstrap 5 RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <style>
        body { background-color: #f8f9fa; min-height: 100vh; display: flex; flex-direction: column; align-items: center; }
        .upload-card { max-width: 450px; width: 100%; margin: auto; }
    </style>
</head>
<body>

<?php
// --- بداية كود التحذير الأمني ---
$current_file_name = basename(__FILE__); // جلب اسم الملف الحالي

if ($current_file_name == 'upload_data_x990.php') {
    echo '
    <div style="width: 90%; max-width: 800px; background: #ffe6e6; border: 2px solid #ff0000; color: #cc0000; padding: 15px; margin: 20px 10px; text-align: center; font-family: tahoma, arial; border-radius: 8px;">
        <h3 style="margin:0;">⚠️ تنبيه أمني خطير</h3>
        <p style="font-weight:bold; font-size:16px;">
            أنت لا تزال تستخدم الاسم الافتراضي للملف (upload_data_x990.php).<br>
            هذا يجعل الصفحة مكشوفة للجميع! يرجى الذهاب لمدير الملفات وتغيير اسم هذا الملف فوراً إلى اسم سري لا يعلمه غيرك.
        </p>
    </div>
    ';
}
?>

<div class="container d-flex justify-content-center">
    <div class="card upload-card shadow">
        <div class="card-header bg-primary text-white text-center py-3">
            <h4 class="mb-0 fs-5">رفع ملف البيانات</h4>
        </div>
        <div class="card-body p-4">

            <?php if (!$isLoggedIn): ?>
                <!-- نموذج تسجيل الدخول -->
                <?php if (isset($loginError)): ?>
                    <div class="alert alert-danger"><?php echo $loginError; ?></div>
                <?php endif; ?>
                <form action="" method="POST">
                    <div class="mb-3">
                        <label for="loginPass" class="form-label">🔒 أدخل كلمة المرور للوصول:</label>
                        <input type="password" class="form-control" id="loginPass" name="login_password" required autofocus>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">دخول</button>
                    </div>
                </form>
            <?php else: ?>
                <!-- واجهة الرفع (بعد تسجيل الدخول) -->
                <div class="text-start mb-3">
                    <a href="?logout" class="btn btn-sm btn-outline-danger">تسجيل الخروج</a>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?php echo ($messageType === 'success') ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-4">
                        <label for="excelFile" class="form-label text-muted">اختر ملف Excel (.xlsx)</label>
                        <input class="form-control" type="file" id="excelFile" name="excel_file" accept=".xlsx" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">رفع الملف</button>
                    </div>
                </form>
                
                <div class="mt-4 text-center">
                    <small class="text-muted d-block">ملاحظة: يقبل النظام فقط ملفاً باسم <strong>natiga.xlsx</strong></small>
                    <small class="text-muted d-block mt-1"><?php echo $fileInfoMsg; ?></small>
                    <small class="text-danger d-block mt-1"><?php echo $serverLimitsMsg; ?></small>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<footer class="text-center mt-5 mb-4 text-muted border-top pt-3">
    <strong>تم التطوير بواسطة <a href="https://alielkhedr.com" class="text-decoration-none" style="color: #004080;">علي الخضر</a> &copy; 2026</strong>
</footer>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
