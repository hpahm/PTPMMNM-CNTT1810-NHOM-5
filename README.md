# OpenShare — Hệ thống chia sẻ tệp có kiểm soát quyền tải

Đồ án học phần **Phát triển phần mềm mã nguồn mở** (Đề tài 7).
Ứng dụng viết bằng PHP thuần (PHP 8.1+), không sử dụng framework hoặc Composer. Dữ liệu nghiệp vụ được lưu trong cơ sở dữ liệu cấu hình qua PDO (SQLite mặc định, hỗ trợ MySQL và SQL Server), toàn bộ tệp tải lên được lưu ngoài Web Root tại `storage/files/` nhằm ngăn truy cập trực tiếp qua URL.

---

## Yêu cầu môi trường

- **PHP 8.1 trở lên** với các phần mở rộng: `pdo_sqlite` (hoặc `pdo_mysql` / `pdo_sqlsrv`), `mbstring`, `xml`, `dom`, `fileinfo`, `zlib` (mặc định có sẵn trong PHP).
- Tùy chọn: `zip` (`ZipArchive`) để tăng tốc xử lý DOCX và nén ZIP; nếu chưa bật, hệ thống tự động sử dụng bộ xử lý ZIP thuần PHP trong `src/zip.php`.

---

## Chạy thử trên máy cục bộ

Sau khi clone repository, chuyển vào thư mục gốc dự án:

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

Truy cập <http://127.0.0.1:8127> và vào `/register.php` để đăng ký tài khoản sử dụng. Tài khoản đăng ký đầu tiên sẽ tự động được cấp quyền **Quản trị viên (`admin`)**; các tài khoản sau nhận vai trò người dùng thông thường.

Chạy `bash lab.sh reset` (hoặc `.\lab.ps1 reset`) để làm sạch toàn bộ CSDL và thư mục `storage/files/`. Không đưa dữ liệu thật hoặc tệp riêng tư lên kho mã nguồn.

---

## Cấu hình cơ sở dữ liệu

Mặc định ứng dụng dùng SQLite (chế độ WAL, giới hạn bộ nhớ đệm thấp) tại `storage/openshare.sqlite`. Có thể chuyển sang MySQL (`OPENSHARE_DB_DRIVER=mysql`) hoặc SQL Server (`OPENSHARE_DB_DRIVER=sqlsrv`). Chi tiết biến môi trường và lược đồ bảng xem tại [database/README.md](database/README.md) và [database/schema.sql](database/schema.sql).

Hướng dẫn triển khai lên VPS Ubuntu tối ưu cho RAM 300–400 MB xem tại [DEPLOY_VPS.md](DEPLOY_VPS.md).

---

## Chức năng chính

- **Quản lý Drive và thư mục:** Tạo, đổi tên, di chuyển, xóa thư mục; tải lên từng tệp hoặc cả thư mục theo cây thư mục con; kéo thả tệp, lọc theo trạng thái (Tất cả / Đang chia sẻ / Riêng tư), tìm kiếm và phân trang (50 tệp/trang).
- **Xác thực và bảo mật:** Đăng ký/đăng nhập bằng email `@gmail.com`, băm mật khẩu `password_hash`, quản lý hồ sơ và đổi mật khẩu (`/profile.php`), chống CSRF bằng token, chống Session Fixation, bảo vệ cookie phiên (`HttpOnly`, `SameSite=Lax`) và giới hạn tần suất thử sai (Rate Limiting) lưu trên CSDL theo IP.
- **Kiểm soát tệp tải lên:** Giới hạn tối đa 10 MB mỗi tệp, hạn mức lưu trữ mặc định 1 GB/tài khoản, kiểm tra phần mở rộng hợp lệ kết hợp kiểm tra mã MIME thực tế, kiểm tra dung lượng ổ đĩa trống và tính mã băm SHA-256 phát hiện trùng lặp.
  - Định dạng hỗ trợ: Tài liệu (`pdf`, `txt`, `md`, `csv`, `json`, `xml`, `log`, `rtf`, `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`, `odp`), Hình ảnh (`jpg`, `jpeg`, `png`, `gif`, `webp`, `bmp`), Âm thanh/Video (`mp3`, `wav`, `mp4`, `webm`), Lưu trữ (`zip`, `rar`, `7z`, `tar`, `gz`).
- **Xem trước và chỉnh sửa trực tiếp trên trình duyệt:**
  - Xem trực tiếp PDF, hình ảnh, âm thanh và video qua luồng streaming bảo mật.
  - Chỉnh sửa và lưu trực tiếp nội dung tệp văn bản (`txt`, `md`, `csv`, `json`, `xml`, `log`) và tài liệu `docx` ngay trên trình duyệt (hỗ trợ phím tắt `Ctrl+S`).
- **Chia sẻ tệp có kiểm soát:**
  - Chia sẻ công khai qua liên kết hoặc chỉ định đích danh email người nhận.
  - Thiết lập thời hạn hiệu lực (1–365 ngày), giới hạn số lượt tải (1–1000 lượt) và mật khẩu bảo vệ tùy chọn (có thể cập nhật hoặc gỡ mật khẩu trên liên kết đang hoạt động).
  - Người nhận xác nhận lưu tệp vào mục "Được chia sẻ với tôi" (`/shared.php`) hoặc từ chối lời mời.
- **Hàng đợi FIFO khi tranh chấp lượt tải cuối:** Khi liên kết chỉ còn 1 lượt tải cuối cùng, các yêu cầu đồng thời được xử lý trong transaction nguyên tử; yêu cầu đến trước được cấp lượt, các yêu cầu sau vào hàng đợi FIFO và nhận trang chờ tự động kiểm tra lại.
- **Thùng rác và khôi phục:** Xóa tệp/thư mục sẽ chuyển vào Thùng rác (`/trash.php`) để khôi phục hoặc xóa vĩnh viễn; ngăn xóa tệp khi vẫn còn liên kết chia sẻ hợp lệ.
- **Báo cáo và nhật ký hoạt động:** Thống kê dung lượng, lượt tải, cảnh báo khi bộ nhớ đạt trên 80% hoặc mất tệp vật lý, xuất báo cáo CSV chuẩn UTF-8 BOM tại `/report.php`, xem nhật ký truy cập tại `/logs.php`.

---

## Đối chiếu yêu cầu chức năng

| Yêu cầu | Đường dẫn / Lệnh sử dụng |
|---|---|
| Đăng ký, đăng nhập và quản lý hồ sơ | `/register.php`, `/login.php`, `/profile.php` |
| Quản lý thư mục, tệp và thùng rác | `/`, `/trash.php`, `/file-action.php`, `/folder-action.php` |
| Kiểm tra tệp tải lên và quota | `/upload-file.php`, `/upload-directory.php`, `/report.php` |
| Xem trước và chỉnh sửa trực tiếp | `/preview.php`, `/save-docx.php`, `/save-text.php` |
| Chia sẻ, mật khẩu liên kết và lời mời | `/share.php`, `/shared.php`, `/accept-share.php`, `/decline-share.php` |
| Kiểm tra quyền tải và hàng đợi FIFO | `/download.php`, `/revoke.php` |
| Dọn tệp hết hạn và thùng rác quá hạn | `bash lab.sh cleanup` hoặc `.\lab.ps1 cleanup` (thêm `--delete` để xóa) |
| Tìm kiếm, lọc, nhật ký và xuất CSV | `/`, `/logs.php`, `/report.php?format=csv` |

---

## Kiểm thử tự động

Chạy toàn bộ bộ kiểm thử (sử dụng CSDL tạm cô lập, không ảnh hưởng dữ liệu thật):

- Linux / macOS / WSL: `bash lab.sh test` (hoặc `php tests/run.php`)
- Windows: `.\lab.ps1 test`

Bộ kiểm thử bao gồm 37 phép thử cho các nhóm: xác thực mật khẩu, kiểm tra phần mở rộng và MIME, quota, khóa giao dịch và hàng đợi FIFO nguyên tử, hết hạn/thu hồi liên kết, phân quyền sở hữu, chống Path Traversal, tìm kiếm người nhận, cô lập lời mời chia sẻ, giới hạn tần suất brute-force và đọc/ghi DOCX thuần PHP.
