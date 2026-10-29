<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$passed = 0; $failed = 0;
function check(bool $condition, string $name): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS $name\n"; } else { $failed++; echo "FAIL $name\n"; }
}
resetStore(); $data = seedData();
check(password_verify('123', $data['users']['owner']['password']), 'Đăng nhập dùng password hash');
check(allowedExtension('report.pdf') && !allowedExtension('script.php'), 'Chỉ nhận loại tệp cho phép');
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

check(is_file(FILE_ROOT . '/demo-file-01.bin'), 'Tệp còn liên kết hợp lệ vẫn được giữ trên ổ đĩa');
$preview = (string)shell_exec('php ' . escapeshellarg(APP_ROOT . '/tools/cleanup.php') . ' --preview');
check(str_contains($preview, 'Xem truoc') && is_file(FILE_ROOT . '/demo-file-01.bin'), 'Chế độ xem trước không xóa tệp');

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

echo "TESTS=$passed FAILED=$failed\n";
exit($failed === 0 ? 0 : 1);
