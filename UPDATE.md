# Nhật ký cập nhật hệ thống OpenShare

Tài liệu này tổng hợp các thay đổi về kiến trúc, hiệu năng, bảo mật và tính năng mới nhằm đưa OpenShare từ phiên bản thực hành sang môi trường vận hành thực tế (production) trên VPS có tài nguyên hạn chế (300–400 MB RAM).

---

## I. Tối ưu kiến trúc cơ sở dữ liệu và bộ nhớ (RAM)

### 1. Chuyển từ nạp toàn bộ CSDL sang truy vấn SQL có chọn lọc
- **Vấn đề trước đây:** Toàn bộ dữ liệu từ 7 bảng (`users`, `folders`, `files`, `shares`, `share_acceptances`, `share_queue`, `access_logs`) được nạp vào mảng PHP qua `readStore()`, và mỗi thao tác ghi (`withStore()`) thực hiện xóa sạch rồi chèn lại toàn bộ bảng.
- **Giải pháp:**
  - Chuyển toàn bộ các endpoint trong `public/` và `tools/cleanup.php` sang sử dụng câu lệnh **Prepared Statements** trực tiếp theo khóa chính và điều kiện lọc (`WHERE`, `JOIN`, `EXISTS`, `LIMIT/OFFSET`).
  - Tái sử dụng kết nối `PDO` (request-scoped singleton) và cache thông tin người dùng hiện tại (`currentUser()`) trong phạm vi một HTTP request.
  - Tính dung lượng đã dùng (`userUsageBytes()`) và đếm lời mời chia sẻ (`countPendingInvitationsForUser()`) trực tiếp bằng hàm tổng hợp SQL (`SUM`, `COUNT`).

### 2. Tinh chỉnh SQLite cho môi trường RAM thấp và xử lý đồng thời
- Kích hoạt chế độ **WAL (Write-Ahead Logging)** kết hợp `PRAGMA synchronous = NORMAL` và `PRAGMA busy_timeout = 5000` để giảm xung đột khóa khi nhiều yêu cầu đọc/ghi cùng lúc.
- Giới hạn bộ nhớ đệm trang của SQLite (`PRAGMA cache_size = -2000`, tương đương tối đa ~2 MB) và chuyển bảng tạm sang đĩa (`PRAGMA temp_store = FILE`).
- Bổ sung các chỉ mục (`idx_files_owner_folder`, `idx_files_hash`, `idx_folders_owner_parent`, `idx_queue_share`, `idx_logs_user`, `idx_logs_at`) và sửa ràng buộc duy nhất tên thư mục thành `UNIQUE (owner_id, parent_id, name)`.

### 3. Truyền tải tệp theo luồng (Streaming I/O)
- Hàm `streamFileResponse()` thực hiện đóng khóa phiên (`session_write_close()`), xóa bộ đệm đầu ra (`ob_end_clean()`) và đọc/trả tệp theo từng khối 64 KB thay vì nạp toàn bộ tệp vào RAM.
- Tính năng xuất báo cáo CSV tại `/report.php` truy vấn và ghi từng dòng trực tiếp ra `php://output`.

---

## II. Cải thiện 

### 1. Xử lý ZIP và DOCX thuần PHP (`src/zip.php`)
- Loại bỏ hoàn toàn lệnh gọi tiến trình ngoài `exec('7z.exe ...')` để tương thích trực tiếp trên VPS Linux/Ubuntu và Windows mà không cần cài đặt công cụ bên thứ ba.
- Triển khai bộ đọc/ghi ZIP dùng thư viện `zlib` tích hợp sẵn trong PHP (ưu tiên `ZipArchive` nếu máy chủ có cài đặt `php-zip`), kèm giới hạn dung lượng giải nén để phòng chống tấn công zip-bomb.
- Việc đọc và cập nhật nội dung `word/document.xml` của tệp DOCX được thực hiện trực tiếp trên cấu trúc ZIP, không cần giải nén toàn bộ ra thư mục tạm.
- Tải xuống thư mục (`/download-folder.php`) đóng gói tệp nén ZIP theo đúng cấu trúc thư mục con và tên tệp gốc (`original_name`).

### 2. Chế độ Production không dữ liệu mẫu
- Xóa bỏ hàm `seedData()` và dữ liệu demo (tệp giả, liên kết giả, tài khoản mẫu `owner@gmail.com`, `recipient@gmail.com`, `admin@gmail.com`).
- Khi triển khai mới, hệ thống bắt đầu với CSDL trống; tài khoản đầu tiên đăng ký qua `/register.php` tự động nhận vai trò Quản trị viên (`admin`), các tài khoản đăng ký sau nhận vai trò người dùng thông thường (`recipient`).

---

## III. Bảo mật hệ thống (Security Hardening)

- **Giới hạn tần suất thử sai (Rate Limiting) lưu trên CSDL:** Chuyển bộ đếm thử sai từ `$_SESSION` sang bảng `rate_limits` theo địa chỉ IP (`clientIp()`), ngăn chặn tấn công brute-force tại `/login.php` và `/preview.php` ngay cả khi client không lưu cookie phiên.
- **Bảo vệ phiên làm việc và HTTP Headers:** Cấu hình session cookie với `httponly`, `samesite=Lax`, `session.use_strict_mode=1`, tái tạo Session ID sau khi đăng nhập hoặc đổi mật khẩu, và bổ sung header `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`.
- **Kiểm soát tệp tải lên và đường dẫn:** Kiểm tra phần mở rộng hợp lệ kết hợp kiểm tra MIME thực tế (`fileinfo`/magic bytes); chặn tuyệt đối các định dạng có khả năng thực thi mã hoặc gây XSS (`php`, `svg`, `html`, `exe`, `sh`). Truy xuất tệp vật lý bắt buộc đi qua `safeStoredPath()` nằm ngoài Web Root (`storage/files/`).
- **Phát hiện tệp trùng lặp (SHA-256):** Lưu mã băm `file_hash` khi tải tệp lên để nhận diện tệp có nội dung trùng lặp.

---

## IV. Tính năng mới và nâng cấp 

- **Chỉnh sửa trực tiếp DOCX và văn bản trên trình duyệt:**
  - Bổ sung `/save-text.php` và nâng cấp `/preview.php` để chỉnh sửa trực tiếp các định dạng văn bản thuần (`txt`, `md`, `csv`, `json`, `xml`, `log`) cùng DOCX, hỗ trợ phím tắt `Ctrl+S` / `Cmd+S` và kiểm tra quota khi lưu.
  - Hỗ trợ xem/nghe trực tiếp các định dạng ảnh (`webp`, `gif`, `bmp`), âm thanh (`mp3`, `wav`) và video (`mp4`, `webm`).
- **Mở rộng danh sách định dạng tải lên an toàn:** Bổ sung nhóm tài liệu văn phòng (`doc`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`, `odp`, `rtf`), dữ liệu cấu trúc (`md`, `csv`, `json`, `xml`, `log`), đa phương tiện và nén (`rar`, `7z`, `tar`, `gz`).
- **Thùng rác và khôi phục dữ liệu (`/trash.php`):**
  - Chuyển thao tác xóa tệp (`/delete.php`) và xóa thư mục (`/folder-action.php`) sang xóa mềm (`deleted_at`), cho phép khôi phục hoặc xóa vĩnh viễn tại `/trash.php`.
  - Công cụ `tools/cleanup.php` tự động dọn cả tệp có liên kết hết hạn và tệp nằm trong thùng rác quá 30 ngày.
- **Quản lý hồ sơ cá nhân và đổi mật khẩu (`/profile.php`):** Cho phép người dùng cập nhật tên hiển thị và đổi mật khẩu sau khi xác thực mật khẩu hiện tại.
- **Đổi tên, di chuyển tệp và phân trang Drive:** Bổ sung API `/file-action.php` cho phép đổi tên hoặc di chuyển tệp vào thư mục chỉ định trên giao diện; phân trang danh sách tệp tại `/` (50 tệp/trang).
- **Hàng đợi tải FIFO tự động làm mới và cập nhật mật khẩu chia sẻ:** Trình duyệt khi rơi vào hàng đợi lượt tải cuối (HTTP `202`) sẽ hiển thị trang chờ tự động kiểm tra lại sau mỗi 3 giây; chủ sở hữu có thể đặt, thay đổi hoặc gỡ mật khẩu của liên kết chia sẻ đang hoạt động tại `/share.php`.

---

## V. Tinh chỉnh DOCX, Clean URLs và Trải nghiệm chuyển tab

### 1. Chỉnh sửa DOCX tại chỗ bảo toàn nguyên vẹn biểu mẫu, bảng biểu và ảnh nhúng
- **Vấn đề trước đây:** Khi chỉnh sửa tệp DOCX trên giao diện web, hệ thống lấy toàn bộ văn bản thuần (`innerText`) và tái tạo tệp XML mới, dẫn đến mất toàn bộ định dạng bảng (`w:tbl`), mất liên kết ảnh trong `word/media/`, vỡ layout biểu mẫu và làm hỏng tệp khi tải về mở bằng Microsoft Word.
- **Giải pháp:**
  - `readDocxHtml()` gắn mã định danh đoạn (`data-pid`) cho từng đoạn văn (`w:p`) trong nội dung chính và trong các ô bảng (`w:tc`), đồng thời bảo vệ các phần tử nhúng bằng `contenteditable="false"`.
  - `saveDocxContent()` nạp tệp `word/document.xml` gốc và cập nhật văn bản trực tiếp tại các đoạn được chỉnh sửa (in-place XML update), bảo toàn tuyệt đối thuộc tính đoạn (`w:pPr`), cấu trúc bảng (`w:tbl`, `w:tcPr`), đối tượng đồ họa/ảnh nhúng (`w:drawing`, `w:pict`), phần đầu/chân trang và cài đặt trang (`w:sectPr`).
  - Tệp tải về giữ nguyên vẹn 100% định dạng, biểu mẫu và hình ảnh gốc khi mở trên Microsoft Word, LibreOffice hoặc Google Docs.

### 2. Định dạng Clean URLs (Không hiển thị phần mở rộng `.php`)
- Chuẩn hóa toàn bộ hệ thống đường dẫn nội bộ không còn đuôi `.php` (ví dụ `/shared`, `/logs`, `/report`, `/trash`, `/profile`, `/login`, `/register`, `/preview`, `/share`, `/download`).
- Bổ sung `router.php` phục vụ việc chạy thử nghiệm mượt mà trên môi trường PHP Built-in Server (`lab.ps1 run` và `lab.sh run`).
- Cập nhật cấu hình Nginx trong hướng dẫn triển khai (`DEPLOY_VPS.md`) với chỉ thị `try_files $uri $uri/ $uri.php$is_args$args /index.php$is_args$args;`, giúp máy chủ production tự động xử lý Clean URLs mà vẫn hỗ trợ các URL cũ có đuôi `.php` để tương thích ngược.

### 3. Tối ưu hóa chuyển tab và điều hướng trang mượt mà (Instant Navigation)
- Triển khai cơ chế điều hướng tức thì không giật/chớp trắng màn hình khi người dùng chuyển đổi giữa các mục ("Tệp của tôi", "Được chia sẻ với tôi", "Nhật ký", "Bộ nhớ lưu trữ", "Thùng rác") hoặc thao tác bộ lọc danh sách tệp.
- Bổ sung hiệu ứng chuyển động CSS mượt mà cho bảng tệp và lưới hiển thị tệp, nâng cao trải nghiệm ứng dụng web hiện đại.
- Loại bỏ toàn bộ Emoji, thay thế bằng bộ SVG Vector phẳng và tích hợp Logo OpenShare độc quyền để tránh vi phạm bản quyền.

