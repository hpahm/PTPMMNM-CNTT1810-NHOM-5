<?php
declare(strict_types=1);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openshare-test-' . getmypid();
if (!is_dir($testRoot)) mkdir($testRoot, 0770, true);
putenv('OPENSHARE_DB_FILE=' . $testRoot . DIRECTORY_SEPARATOR . 'test.sqlite');
putenv('OPENSHARE_FILE_ROOT=' . $testRoot . DIRECTORY_SEPARATOR . 'files');
require __DIR__ . '/../src/bootstrap.php';

$passed = 0; $failed = 0;
function check(bool $condition, string $name): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS $name\n"; } else { $failed++; echo "FAIL $name\n"; }
}
resetStore();
withStore(function (array &$store): void {
    $pw = password_hash('123', PASSWORD_DEFAULT);
    $store['users']['owner'] = ['id'=>'owner','name'=>'Chủ tệp','email'=>'owner@gmail.com','role'=>'owner','password'=>$pw,'quota'=>USER_QUOTA_BYTES];
    $store['users']['recipient'] = ['id'=>'recipient','name'=>'Người nhận','email'=>'recipient@gmail.com','role'=>'recipient','password'=>$pw,'quota'=>USER_QUOTA_BYTES];
    $store['folders']['root-owner'] = ['id'=>'root-owner','owner_id'=>'owner','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)];
    $store['folders']['root-recipient'] = ['id'=>'root-recipient','owner_id'=>'recipient','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)];
    $store['files']['test-file-01'] = [
        'id'=>'test-file-01','owner_id'=>'owner','folder_id'=>'root-owner',
        'original_name'=>'tai-lieu-1.txt','stored_name'=>'test-file-01.bin',
        'size'=>16,'mime'=>'text/plain','created_at'=>date(DATE_ATOM),'download_count'=>0
    ];
    $store['shares']['test-share-01'] = [
        'id'=>'test-share-01','file_id'=>'test-file-01','owner_id'=>'owner',
        'recipient_id'=>'recipient','expires_at'=>date(DATE_ATOM, time()+3600),
        'max_downloads'=>5,'downloads'=>0,'password_hash'=>null,
        'created_at'=>date(DATE_ATOM),'revoked_at'=>null,'queue'=>[]
    ];
});
file_put_contents(FILE_ROOT . '/test-file-01.bin', "OpenShare test\n", LOCK_EX);
$data = readStore();
check(password_verify('123', $data['users']['owner']['password']), 'Đăng nhập dùng password hash');
check(allowedExtension('report.pdf') && allowedExtension('sheet.xlsx') && allowedExtension('clip.mp4') && allowedExtension('pic.webp') && !allowedExtension('script.php') && !allowedExtension('evil.svg'), 'Chỉ nhận loại tệp cho phép (mở rộng an toàn)');
check(isGmailAddress('user@gmail.com') && !isGmailAddress('user@example.com'), 'Tài khoản phải dùng Gmail');
check(userUsage(['files'=>[['owner_id'=>'owner','size'=>10],['owner_id'=>'recipient','size'=>5]]], 'owner') === 10, 'Tính quota theo chủ sở hữu');
check(array_key_exists('root-owner', $data['folders']), 'Mỗi tài khoản có thư mục gốc');
check(diskHasSpace(1), 'Ổ đĩa còn chỗ cho tệp nhỏ');
$share = ['revoked_at'=>null,'expires_at'=>date(DATE_ATOM,time()+60),'downloads'=>0,'max_downloads'=>1];
check(validShare($share,time()), 'Liên kết còn hạn được chấp nhận');
$share['downloads'] = 1; check(!validShare($share,time()), 'Từ chối vượt giới hạn lượt tải');
$share['downloads'] = 0; $share['expires_at'] = date(DATE_ATOM,time()-1); check(!validShare($share,time()), 'Từ chối liên kết hết hạn');
$share['expires_at'] = date(DATE_ATOM,time()+60); $share['revoked_at'] = date(DATE_ATOM); check(!validShare($share,time()), 'Từ chối liên kết đã thu hồi');
withStore(function(array &$store): void {
    if (!isset($store['users']['admin'])) {
        $store['users']['admin'] = [
            'id' => 'admin', 'name' => 'Admin Test', 'email' => 'admintest@gmail.com',
            'role' => 'admin', 'password' => 'test', 'quota' => USER_QUOTA_BYTES
        ];
    }
    $store['files']['f1'] = ['id'=>'f1','owner_id'=>'owner','original_name'=>'a.txt','stored_name'=>'a.bin','size'=>12,'mime'=>'text/plain','created_at'=>date(DATE_ATOM),'download_count'=>0];
    $store['shares']['s1'] = ['id'=>'s1','file_id'=>'f1','owner_id'=>'owner','recipient_id'=>'recipient','expires_at'=>date(DATE_ATOM,time()+100),'max_downloads'=>1,'downloads'=>0,'password_hash'=>null,'created_at'=>date(DATE_ATOM),'revoked_at'=>null];
});
$before = readStore()['shares']['s1']['downloads'];
withStore(function(array &$store): void { if (validShare($store['shares']['s1'],time())) $store['shares']['s1']['downloads']++; });
withStore(function(array &$store): void { if (validShare($store['shares']['s1'],time())) $store['shares']['s1']['downloads']++; });
$after = readStore()['shares']['s1']['downloads'];
check($before === 0 && $after === 1, 'Khóa tệp ngăn cấp lượt tải vượt mức');
withStore(function(array &$store): void {
    $store['shares']['s1']['downloads'] = 0;
    $store['shares']['s1']['queue'] = [];
});
withStore(function(array &$store): void { check(requestDownloadSlot($store['shares']['s1'], 'recipient', time())['status'] === 'granted', 'Người đầu tiên nhận lượt cuối'); });
withStore(function(array &$store): void { check(requestDownloadSlot($store['shares']['s1'], 'owner', time())['position'] === 1, 'Yêu cầu thứ hai vào hàng đợi vị trí 1'); });
withStore(function(array &$store): void { check(requestDownloadSlot($store['shares']['s1'], 'admin', time())['position'] === 2, 'Yêu cầu thứ ba vào hàng đợi vị trí 2'); });
check((new ReflectionFunction('withStore'))->isUserDefined(), 'Kho dữ liệu dùng hàm cập nhật tập trung');
$logs = readStore()['logs']; check(is_array($logs), 'Nhật ký truy cập có cấu trúc');

// Các phép thử bắt buộc theo đặc tả phần 6.
withStore(function(array &$store): void {
    $store['files']['required-a'] = [
        'id'=>'required-a', 'owner_id'=>'owner', 'folder_id'=>'root-owner',
        'original_name'=>'required.txt', 'stored_name'=>'required-a.bin',
        'size'=>12, 'mime'=>'text/plain', 'created_at'=>date(DATE_ATOM),
        'download_count'=>0,
    ];
    $store['shares']['required-share'] = [
        'id'=>'required-share', 'file_id'=>'required-a', 'owner_id'=>'owner',
        'recipient_id'=>'recipient', 'expires_at'=>date(DATE_ATOM,time()+60),
        'max_downloads'=>1, 'downloads'=>0, 'password_hash'=>null,
        'created_at'=>date(DATE_ATOM), 'revoked_at'=>null, 'queue'=>[],
    ];
});
$first = null; $second = null;
withStore(function(array &$store) use (&$first): void {
    $first = requestDownloadSlot($store['shares']['required-share'], 'recipient', time());
});
withStore(function(array &$store) use (&$second): void {
    $second = requestDownloadSlot($store['shares']['required-share'], 'recipient', time());
});
check($first['status'] === 'granted' && $second['status'] === 'queued', 'Hai yêu cầu cùng lúc: chỉ một yêu cầu được cấp lượt');

$owner = readStore()['users']['owner']; $recipient = readStore()['users']['recipient'];
$requiredFile = readStore()['files']['required-a'];
check(userCanManageFile($owner, $requiredFile) && !userCanManageFile($recipient, $requiredFile), 'Tài khoản B không sửa hoặc xóa được tệp của A');
check(safeStoredPath(['stored_name'=>'../data.json']) === null, 'Từ chối đường dẫn tệp bất thường');

withStore(function(array &$store): void {
    $store['shares']['required-share']['expires_at'] = date(DATE_ATOM,time()-1);
});
check(!validShare(readStore()['shares']['required-share'], time()), 'Liên kết hết hạn không được trả nội dung');
withStore(function(array &$store): void {
    $store['shares']['required-share']['expires_at'] = date(DATE_ATOM,time()+60);
    $store['shares']['required-share']['revoked_at'] = date(DATE_ATOM);
});
check(!validShare(readStore()['shares']['required-share'], time()), 'Liên kết đã thu hồi không được trả nội dung');
check(!allowedExtension('malware.exe') && !detectedMimeIsAllowed('required.txt', __FILE__), 'Từ chối tệp sai loại hoặc nội dung không khớp');

// Phép thử: Chấp nhận video MP4 quay từ trình duyệt (Vivaldi/QuickTime container ftypqt)
$qtTmp = tempnam(sys_get_temp_dir(), 'os_qt_');
file_put_contents($qtTmp, pack('N', 20) . 'ftypqt  ' . pack('N', 0) . 'qt  ');
check(detectedMimeIsAllowed('Drive của tôi · OpenShare - Vivaldi 2026-11-19 03-06-15.mp4', $qtTmp), 'Chấp nhận tệp video MP4/QuickTime quay màn hình từ Vivaldi');
@unlink($qtTmp);

check(is_file(FILE_ROOT . '/test-file-01.bin'), 'Tệp còn liên kết hợp lệ vẫn được giữ trên ổ đĩa');
$preview = (string)shell_exec('php ' . escapeshellarg(APP_ROOT . '/tools/cleanup.php') . ' --preview');
check(str_contains($preview, 'Xem truoc') && is_file(FILE_ROOT . '/test-file-01.bin'), 'Chế độ xem trước không xóa tệp');

// Phép thử mở rộng: Tìm kiếm người nhận qua email và bảo mật lời mời
$storeData = readStore();
check(findUserByEmail($storeData, 'recipient@gmail.com') !== null, 'Tìm thấy tài khoản người nhận theo email chính xác');
check(findUserByEmail($storeData, 'RECIPIENT@GMAIL.COM') !== null, 'Tìm kiếm email không phân biệt chữ hoa thường');
check(findUserByEmail($storeData, 'nonexistent@gmail.com') === null, 'Từ chối tài khoản email chưa tồn tại');

// Phép thử: Luồng lời mời chia sẻ và cô lập danh sách "Chia sẻ với tôi" theo từng user
withStore(function(array &$store): void {
    $store['files']['invite-file'] = [
        'id' => 'invite-file', 'owner_id' => 'owner', 'folder_id' => 'root-owner',
        'original_name' => 'invite.pdf', 'stored_name' => 'invite.bin',
        'size' => 100, 'mime' => 'application/pdf', 'created_at' => date(DATE_ATOM),
        'download_count' => 0
    ];
    $store['shares']['invite-share'] = [
        'id' => 'invite-share', 'file_id' => 'invite-file', 'owner_id' => 'owner',
        'recipient_id' => 'recipient', 'expires_at' => date(DATE_ATOM, time() + 3600),
        'max_downloads' => 10, 'downloads' => 0, 'password_hash' => null,
        'created_at' => date(DATE_ATOM), 'revoked_at' => null, 'queue' => [],
        'acceptances' => [], 'accepted' => false
    ];
});

$freshStore = readStore();
$recipientInvites = getPendingInvitations($freshStore, 'recipient');
$ownerInvites = getPendingInvitations($freshStore, 'owner');
$adminInvites = getPendingInvitations($freshStore, 'admin');

check(count(array_filter($recipientInvites, fn($item) => $item[0]['id'] === 'invite-share')) === 1, 'Người nhận thấy lời mời chia sẻ mới từ chủ tệp');
check(count(array_filter($ownerInvites, fn($item) => $item[0]['id'] === 'invite-share')) === 0, 'Chủ tệp không thấy lời mời của chính mình trong danh sách nhận');
check(count(array_filter($adminInvites, fn($item) => $item[0]['id'] === 'invite-share')) === 0, 'Tài khoản khác không thấy lời mời không thuộc về mình');

// Người nhận chấp nhận lời mời
withStore(function(array &$store): void {
    $store['shares']['invite-share']['acceptances']['recipient'] = date(DATE_ATOM);
});
$afterAcceptStore = readStore();
$shareRow = $afterAcceptStore['shares']['invite-share'];
check(isShareAcceptedByUser($shareRow, 'recipient'), 'Người nhận được đánh dấu đã chấp nhận lưu tệp');
check(!isShareAcceptedByUser($shareRow, 'admin'), 'Tài khoản khác không bị tính là đã chấp nhận tệp này');

// Phép thử bảo mật: Cơ chế Rate Limiting chống Brute-force
$_SESSION = [];
for ($i = 0; $i < 5; $i++) {
    recordFailedAttempt('test_action');
}
check(isRateLimited('test_action', 5, 300), 'Kích hoạt giới hạn khi thử sai quá 5 lần');
clearRateLimit('test_action');
check(!isRateLimited('test_action', 5, 300), 'Xóa bộ đếm giới hạn khi thực hiện thành công');

// Phép thử: Xử lý ZIP & DOCX thuần PHP (không cần 7z.exe)
$docxTestPath = FILE_ROOT . '/test-doc.docx';
zipWriteArchive($docxTestPath, [
    ['name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>'],
    ['name' => 'word/document.xml', 'data' => buildDocxDocumentXml("Xin chào OpenShare\nDòng thứ hai")],
]);
$docxHtml = readDocxHtml($docxTestPath);
check(str_contains($docxHtml, 'Xin chào OpenShare') && str_contains($docxHtml, 'Dòng thứ hai'), 'Đọc DOCX thuần PHP không cần 7z');
saveDocxContent($docxTestPath, 'Đã cập nhật nội dung');
$paragraphs = readDocxParagraphs($docxTestPath);
check(($paragraphs[0] ?? '') === 'Đã cập nhật nội dung', 'Lưu DOCX thuần PHP không cần 7z');

// Phép thử: Chỉnh sửa DOCX tại chỗ bảo toàn cấu trúc bảng và ảnh nhúng
$docWithTableXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>'
    . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>Tiêu đề biểu mẫu</w:t></w:r></w:p>'
    . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Ô bảng 1</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr>'
    . '</w:body></w:document>';
zipWriteArchive($docxTestPath, [
    ['name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>'],
    ['name' => 'word/document.xml', 'data' => $docWithTableXml],
    ['name' => 'word/header1.xml', 'data' => '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>Header mẫu</w:t></w:r></w:p></w:hdr>'],
    ['name' => 'word/footer1.xml', 'data' => '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>Footer mẫu</w:t></w:r></w:p></w:ftr>'],
    ['name' => 'word/media/logo.png', 'data' => 'FAKE_IMAGE_BYTES'],
]);
$previewHtmlWithHeaderFooter = readDocxHtml($docxTestPath);
check(str_contains($previewHtmlWithHeaderFooter, 'Header mẫu') && str_contains($previewHtmlWithHeaderFooter, 'Footer mẫu'), 'Đọc DOCX có Header và Footer không lỗi tham chiếu');
saveDocxContent($docxTestPath, [
    'paragraphs' => [
        ['id' => 0, 'text' => 'Tiêu đề đã sửa'],
        ['id' => 1, 'text' => 'Nội dung ô bảng đã sửa']
    ]
]);
$updatedEntries = zipReadEntries($docxTestPath);
$updatedDocXml = $updatedEntries['word/document.xml'] ?? '';
$hasTable = str_contains($updatedDocXml, '<w:tbl>') && str_contains($updatedDocXml, '</w:tbl>');
$hasImageEntry = isset($updatedEntries['word/media/logo.png']);
$newParas = readDocxParagraphs($docxTestPath);
check($hasTable && $hasImageEntry && ($newParas[0] ?? '') === 'Tiêu đề đã sửa' && ($newParas[1] ?? '') === 'Nội dung ô bảng đã sửa', 'Chỉnh sửa DOCX tại chỗ bảo toàn cấu trúc bảng và ảnh nhúng');

// Phép thử: Hàng đợi SQL nguyên tử (requestDownloadSlotSql)
$db = database();
$db->prepare("UPDATE shares SET downloads = 0, max_downloads = 1 WHERE id = 'test-share-01'")->execute();
$r1 = requestDownloadSlotSql($db, 'test-share-01', 'recipient', time());
$r2 = requestDownloadSlotSql($db, 'test-share-01', 'owner', time());
check($r1['status'] === 'granted' && $r2['status'] === 'queued' && $r2['position'] === 1, 'Cấp lượt và xếp hàng FIFO qua SQL nguyên tử');

// Phép thử: Bộ điều hướng Clean URL (router.php & public/index.php)
$sessionPath = APP_ROOT . '/storage/sessions';
$cmd = 'php -d "session.save_path=' . escapeshellarg($sessionPath) . '" -r "$_SERVER[\'REQUEST_URI\']=\'/login\'; require \'router.php\';"';
$routeOut = (string)shell_exec($cmd);
check(str_contains($routeOut, 'Đăng nhập OpenShare'), 'Bộ điều hướng Clean URL (/login)');

$cmdIndex = 'php -d "session.save_path=' . escapeshellarg($sessionPath) . '" -r "$_SERVER[\'REQUEST_URI\']=\'/login\'; require \'public/index.php\';"';
$indexOut = (string)shell_exec($cmdIndex);
check(str_contains($indexOut, 'Đăng nhập OpenShare'), 'Điều phối Clean URL qua public/index.php tránh vòng lặp chuyển hướng');

echo "TESTS=$passed FAILED=$failed\n";
exit($failed === 0 ? 0 : 1);
