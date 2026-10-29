# Nhật ký cập nhật & Hướng dẫn Triển khai (Deploy OpenShare)

Tài liệu này ghi lại toàn bộ các nâng cấp, sửa đổi logic, vá lỗ hổng bảo mật và hướng dẫn chi tiết cách triển khai OpenShare lên máy chủ VPS.

---

## 🚀 I. Các tính năng & Cải tiến mới

### 1. Khắc phục triệt để lỗi mục "Được chia sẻ với tôi"
- **Độc lập hóa trạng thái chấp nhận (Multi-recipient Acceptances):**
  - Cập nhật cấu trúc bảng `share_acceptances` trong CSDL với khóa chính kết hợp `PRIMARY KEY (share_id, user_id)`.
  - Thay thế thuộc tính `accepted` dùng chung toàn cục bằng hàm kiểm tra theo từng người dùng `isShareAcceptedByUser($share, $userId)`. Khi một người dùng bấm lưu tệp, trạng thái chỉ áp dụng riêng cho tài khoản đó, không ảnh hưởng đến các tài khoản khác.
- **Loại bỏ việc tự động thêm tệp ngầm:**
  - Xóa bỏ logic tự động đưa tệp công khai vào mục "Đã lưu" chỉ vì người dùng từng bấm xem trước.
  - Mục **Được chia sẻ với tôi** (`/shared.php`) giờ đây phân định rõ ràng 2 phần:
    1. **Lời mời chia sẻ mới:** Đúng tài khoản nhận qua email chỉ định.
    2. **Tệp đã lưu:** Các tệp người dùng đã chủ động xác nhận lưu vào Drive của mình.

### 2. Chia sẻ tệp bằng Email người nhận (Thay thế Dropdown)
- **Bảo vệ danh sách người dùng (Chống User Enumeration):**
  - Xóa bỏ hoàn toàn thẻ `<select>` hiển thị danh sách người dùng trong hệ thống.
  - Thay thế bằng ô nhập email chuẩn: `<input type="email" name="recipient_email" placeholder="nguoinhan@gmail.com">`.
- **Kiểm tra chặt chẽ phía máy chủ:**
  - Xác thực email người nhận đúng định dạng Gmail (`@gmail.com`).
  - Ngăn chặn việc tự chia sẻ cho chính mình (`owner_id === recipient_id`).
  - Tìm kiếm tài khoản trong CSDL qua hàm `findUserByEmail()`. Nếu email chưa tồn tại, hệ thống sẽ đưa ra thông báo thân thiện yêu cầu người nhận đăng ký tài khoản trước.

### 3. Hệ thống Lời mời chia sẻ (Invitations & Notifications)
- **Huy hiệu thông báo (Badge & Alert):**
  - **Trên Sidebar:** Menu *"Được chia sẻ với tôi"* tự động hiển thị số lượng lời mời đang chờ (ví dụ: `Được chia sẻ với tôi [ 1 ]`).
  - **Trên Topbar:** Hiển thị chuông thông báo `📬` kèm số lượng lời mời đang chờ duyệt ngay cạnh thanh tìm kiếm.
  - **Trên Trang chủ Drive:** Hiển thị banner chào mừng nổi bật khi người dùng có lời mời chia sẻ mới.
- **Giao diện thao tác trực tiếp tại `/shared.php`:**
  - Hiển thị danh thiếp lời mời với tên tệp, dung lượng, người gửi (Tên + Email), hạn dùng và lượt tải còn lại.
  - Tích hợp 3 nút thao tác nhanh:
    + **[✓ Chấp nhận]:** Xác nhận lưu tệp vào Drive của mình ngay lập tức.
    + **[✕ Từ chối]:** Tạo mới endpoint `/decline-share.php` cho phép người nhận chủ động từ chối lời mời và ghi nhật ký hoạt động `share_declined`.
    + **[👁 Xem]:** Mở trang xem trước nội dung tệp.

### 4. Nâng cấp Bảo mật toàn diện (Security Hardening)
- **Chống Brute Force mật khẩu (Rate Limiting):**
  - Thêm cơ chế kiểm soát tần suất thử mật khẩu (`isRateLimited()`, `recordFailedAttempt()`, `clearRateLimit()`).
  - Áp dụng tại `/login.php` và `/preview.php`: Tự động khóa tạm thời 5 phút nếu nhập sai quá 5 lần liên tiếp.
- **Bảo mật Cookie & Phiên làm việc (Session Security):**
  - Cấu hình cookie phiên: `httponly = true` (chống XSS), `samesite = 'Lax'` (chống CSRF), `use_strict_mode = 1` (chống Session Fixation).
- **Security HTTP Response Headers:**
  - Tự động gửi các header an toàn: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`.

### 5. Dung lượng bộ nhớ người dùng (Quota)
- Tăng hạn mức lưu trữ mặc định của mỗi tài khoản lên **1 GB** (`1_073_741_824 bytes`).
- Mỗi tệp tải lên tối đa **10 MB**, kiểm tra dung lượng ổ đĩa và hạn mức quota còn lại trước khi ghi đĩa.

### 6. Xóa tài khoản Quản trị viên mẫu & Tự động cấp quyền khi Deploy
- Xóa bỏ tài khoản mẫu `admin@gmail.com` (mật khẩu mặc định `123`) khỏi hệ thống để đảm bảo an toàn tuyệt đối khi triển khai.
- **Cơ chế khởi tạo Quản trị viên an toàn:**
  - Khi triển khai lên VPS mới, người dùng đăng ký tài khoản đầu tiên tại `/register.php` sẽ **tự động được gán vai trò Quản trị viên (`admin`)**.
  - Các tài khoản đăng ký sau đó sẽ tự động nhận vai trò người dùng thông thường (`recipient`), đảm bảo quyền quản trị thuộc về bạn với email và mật khẩu riêng.

---

## 🛠 II. Hướng dẫn Triển khai (Deploy lên VPS)

### 1. Yêu cầu hệ thống
- Hệ điều hành: Ubuntu 22.04 / 24.04 LTS (hoặc Debian 11/12).
- Web Server: Nginx hoặc Apache.
- PHP: Phiên bản PHP 8.1 trở lên với các extension:
  `php-fpm`, `php-sqlite3` (hoặc `php-mysql`), `php-mbstring`, `php-fileinfo`, `php-pdo`.

### 2. Các bước cài đặt trên VPS Ubuntu / Debian

#### Bước 1: Cài đặt PHP và Nginx
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx php8.2 php8.2-fpm php8.2-sqlite3 php8.2-mbstring php8.2-fileinfo git
```

#### Bước 2: Clone mã nguồn về máy chủ
```bash
cd /var/www
sudo git clone https://github.com/hpahm/PTPMMNM-CNTT1810-NHOM-5.git openshare
cd /var/www/openshare
```

#### Bước 3: Phân quyền thư mục lưu trữ
Ứng dụng lưu tệp tin, phiên làm việc và CSDL SQLite trong thư mục `storage/`. Cần cấp quyền ghi cho user `www-data`:
```bash
sudo chown -R www-data:www-data /var/www/openshare/storage
sudo chmod -R 775 /var/www/openshare/storage
```

#### Bước 4: Cấu hình Virtual Host Nginx
Tạo file cấu hình Nginx:
```bash
sudo nano /etc/nginx/sites-available/openshare
```

Dán nội dung cấu hình mẫu (chú ý trỏ root vào thư mục `public`):
```nginx
server {
    listen 80;
    server_name your-domain.com; # Hoặc địa chỉ IP của VPS

    root /var/www/openshare/public;
    index index.php index.html;

    client_max_body_size 20M; # Cho phép upload tệp tối đa (tệp app giới hạn 10MB)

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Chặn truy cập trực tiếp vào các tệp ẩn và thư mục mã nguồn
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Kích hoạt cấu hình và khởi động lại Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/openshare /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
```

#### Bước 5: Truy cập và khởi tạo tài khoản Quản trị viên
1. Mở trình duyệt và truy cập: `http://<IP-VPS-CUA-BAN>/register.php`
2. Nhập họ tên, địa chỉ Gmail cá nhân của bạn và mật khẩu an toàn.
3. Nhấn **Tạo tài khoản**: Tài khoản đầu tiên này sẽ **tự động trở thành Quản trị viên (`admin`)** của toàn bộ hệ thống OpenShare trên VPS!
4. Đăng nhập và bắt đầu sử dụng.

---

## 🧪 III. Kiểm thử hệ thống
Sau khi deploy hoặc chỉnh sửa mã nguồn, bạn có thể chạy bộ kiểm thử tự động ngay trên máy chủ:
```bash
php tests/run.php
```
Kết quả mong đợi: **`TESTS=34 FAILED=0`**.
