# OpenShare — Chia sẻ tệp có kiểm soát quyền tải

Đồ án lớn cho học phần **Phát triển phần mềm mã nguồn mở**, theo đề tài 7.
Ứng dụng PHP thuần, không dùng framework hoặc Composer. Dữ liệu nghiệp vụ được
lưu trong CSDL cấu hình (SQLite, MySQL hoặc SQL Server), còn tệp tải lên nằm ở
`storage/files/`, vì vậy không thể truy cập trực tiếp qua Web.

## Chạy thử

Sau khi clone repository, chuyển vào thư mục dự án:

```bash
cd btl
```

### Trên Linux / macOS / WSL (Bash)

```bash
bash lab.sh check
bash lab.sh test
bash lab.sh run
```

### Trên Windows (PowerShell / Command Prompt)

```powershell
.\lab.ps1 check
.\lab.ps1 test
.\lab.ps1 run
```

Hoặc dùng file batch:
```cmd
lab.bat test
lab.bat run
```

PHP cần bật phần mở rộng `pdo_sqlite` (hoặc `pdo_mysql` nếu dùng MySQL; `pdo_sqlsrv` nếu dùng SQL Server).
Mặc định ứng dụng dùng SQLite tại `storage/openshare.sqlite`. Có thể chuyển
sang MySQL bằng `OPENSHARE_DB_DRIVER=mysql` hoặc SQL Server bằng
`OPENSHARE_DB_DRIVER=sqlsrv`. Tham khảo [database/README.md](database/README.md)
để xem các biến kết nối.

Thiết kế bảng và quan hệ nằm trong [database/README.md](database/README.md)
và [database/schema.sql](database/schema.sql).

Mở <http://127.0.0.1:8127>. Tài khoản mẫu:

| Tài khoản | Mật khẩu | Vai trò |
|---|---|---|
| `owner@gmail.com` | `123` | Chủ tệp |
| `recipient@gmail.com` | `123` | Người nhận liên kết |
| `admin@gmail.com` | `123` | Quản trị viên |

Mỗi lần chạy `bash lab.sh reset` (hoặc `.\lab.ps1 reset`) sẽ khởi tạo lại dữ liệu mẫu. Không đưa dữ
liệu thật hoặc tệp riêng tư vào thư mục `storage/`.

Dữ liệu mẫu sau khi reset gồm 3 tài khoản, 30 tệp giả và 50 liên kết chia sẻ;
các tệp giả được tạo trong `storage/files/` để có thể kiểm thử luồng tải
xuống ngay trên máy thực hành.

## Chức năng chính

- **Giao diện người dùng mô phỏng Google Drive**: Giao diện trực quan, thanh bên (Sidebar) quản lý, bộ nhớ lưu trữ, chuyển đổi xem Dạng danh sách (List view) và Dạng lưới (Grid view), kéo thả tệp tải lên (Drag & Drop).
- **Xác thực & Bảo mật**: Đăng nhập, đăng xuất, đăng ký với email Gmail, bảo vệ chống tấn công CSRF bằng CSRF token, chống Session Fixation.
- **Phân quyền người dùng**: Chủ tệp (owner), Người nhận (recipient), Quản trị viên (admin).
- **Quản lý thư mục & Tệp**: Tạo thư mục mới, tải tệp vào thư mục chỉ định, lọc tệp theo thư mục hoặc trạng thái (Tất cả / Đang chia sẻ / Riêng tư).
- **Kiểm soát tệp tải lên**: Giới hạn tối đa 10 MB, kiểm tra phần mở rộng tệp hợp lệ (PDF, TXT, JPG, PNG, ZIP, DOCX), kiểm tra mã MIME thực tế, kiểm tra dung lượng ổ đĩa và hạn mức Quota cá nhân (50 MB).
- **Chia sẻ tệp linh hoạt**:
  - Tạo liên kết công khai (bất kỳ ai có link) hoặc chỉ định đích danh tài khoản người nhận.
  - Tùy chỉnh số ngày hết hạn (1 - 365 ngày).
  - Tùy chỉnh giới hạn số lượt tải (1 - 1000 lượt).
  - Mật khẩu bảo vệ tùy chọn (được băm bcrypt an toàn).
- **Hàng đợi FIFO đồng thời**: Khi liên kết chỉ còn 1 lượt tải cuối cùng, các yêu cầu đồng thời được tự động xếp hàng FIFO theo thời gian yêu cầu; lượt đến trước được cấp phát, lượt sau nhận trạng thái chờ.
- **Xác nhận lưu tệp chia sẻ**: Người nhận xem trước tệp và chủ động xác nhận lưu vào mục "Được chia sẻ với tôi".
- **Xem trước tệp đa năng**: Hỗ trợ xem trực tiếp các tệp PDF, Ảnh (JPG, PNG), Văn bản (TXT) ngay trên trình duyệt; hỗ trợ tải trực tiếp các tệp DOCX, ZIP.
- **Thu hồi & Xóa an toàn**: Thu hồi liên kết lập tức làm mất hiệu lực; ngăn xóa tệp khi còn liên kết hợp lệ (chống mất liên kết của người nhận); xóa tệp đồng thời xóa tệp vật lý ngoài Web root.
- **Báo cáo & Giám sát**: Biểu đồ phần trăm bộ nhớ, thống kê lượt tải, cảnh báo khi bộ nhớ đạt trên 80%, cảnh báo tệp vật lý bị mất hoặc ổ đĩa sắp đầy, xuất báo cáo ra file CSV (chuẩn UTF-8 BOM hiển thị tiếng Việt trên Excel).
- **Nhật ký hệ thống**: Lưu lại mọi hoạt động (tải lên, tải xuống, từ chối tải, xếp hàng FIFO, tạo/thu hồi chia sẻ, tạo thư mục...).

## Đối chiếu yêu cầu chức năng

| Yêu cầu | Cách sử dụng |
|---|---|
| Đăng nhập và quản lý quota | Đăng nhập tại `/login.php`; quota được kiểm tra khi tải lên và xem tại `/report.php` hoặc thanh bên. |
| Quản lý thư mục và tệp | Tạo thư mục tại `/folders.php`, tải tệp tại `/upload.php`, lọc tệp trên `/`. |
| Kiểm tra tệp trước khi nhận | Giới hạn 10 MB, phần mở rộng cho phép, MIME thực tế, dung lượng đĩa và quota. |
| Liên kết chia sẻ | Chọn tệp → `Chia sẻ`; đặt hạn dùng, số lượt tải và mật khẩu tùy chọn. |
| Kiểm tra quyền trước khi tải | Kiểm tra người nhận, chủ sở hữu, hạn dùng, mật khẩu, lượt tải và đường dẫn vật lý. |
| Thu hồi và lịch sử | Thu hồi tại trang chia sẻ; xem nhật ký tại `/logs.php`. |
| Dọn tệp hết hạn | `bash lab.sh cleanup` (hoặc `.\lab.ps1 cleanup`) để xem trước; thêm `--delete` để xác nhận xóa. |
| Tìm kiếm, lọc và báo cáo | Tìm kiếm trên thanh Topbar, lọc `Tất cả / Đang chia sẻ / Riêng tư`, xuất CSV tại `/report.php`. |

## Phép thử bắt buộc

Chạy kiểm thử:
- Trên Linux/Bash: `bash lab.sh test` (hoặc `php tests/run.php`)
- Trên Windows: `.\lab.ps1 test`

Kiểm tra 7 nhóm: đăng nhập, kiểm tra loại tệp, quota, giới hạn lượt tải đồng thời, hết hạn/thu hồi, chống truy cập chéo chủ sở hữu, và báo cáo.
