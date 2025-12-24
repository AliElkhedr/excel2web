<?php
// إعدادات الصفحة
$uploadDir = __DIR__ . '/'; // حفظ في نفس المجلد الحالي ليتم قراءته بواسطة readExcel.php
$targetFileName = 'natiga.xlsx'; // الاسم الإجباري للملف
$message = '';
$messageType = ''; // success or error
$targetFilePath = $uploadDir . $targetFileName;

// معالجة الطلب عند ضغط زر الرفع
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. التحقق من وجود ملف
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        
        $fileTmpPath = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        
        // 2. التحقق الصارم من اسم الملف وامتداده
        // نستخدم === للمطابقة الحرفية (Case sensitive)
        if ($fileName === $targetFileName) {
            
            // 3. نقل الملف إلى المجلد النهائي (سيقوم باستبدال الملف القديم إن وجد)
            if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
                $message = "تم رفع الملف ($fileName) بنجاح إلى الاستضافة.";
                $messageType = 'success';
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

if ($current_file_name == 'upload_data_x99.php') {
    echo '
    <div style="width: 90%; max-width: 800px; background: #ffe6e6; border: 2px solid #ff0000; color: #cc0000; padding: 15px; margin: 20px 10px; text-align: center; font-family: tahoma, arial; border-radius: 8px;">
        <h3 style="margin:0;">⚠️ تنبيه أمني خطير</h3>
        <p style="font-weight:bold; font-size:16px;">
            أنت لا تزال تستخدم الاسم الافتراضي للملف (upload_data_x99.php).<br>
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
            
            <?php if ($message): ?>
                <div class="alert <?php echo ($messageType === 'success') ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data">
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
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
