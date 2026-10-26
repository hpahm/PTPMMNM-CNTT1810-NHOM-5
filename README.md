# OpenShare — Chia sẻ tệp có kiểm soát quyền tải

Đồ án lớn cho học phần **Phát triển phần mềm mã nguồn mở**, theo đề tài 7.
Ứng dụng PHP thuần, không dùng framework hoặc Composer. Dữ liệu nghiệp vụ được
lưu trong CSDL cấu hình (SQLite, MySQL hoặc SQL Server), còn tệp tải lên nằm ở
`storage/files/`, vì vậy không thể truy cập trực tiếp qua Web.

## Chạy thử

```bash
bash lab.sh check
bash lab.sh test
bash lab.sh run
```

PHP cần bật phần mở rộng `pdo_sqlsrv` và ODBC Driver for SQL Server nếu dùng
SQL Server; nếu dùng MySQL cần bật `pdo_mysql`.
Mặc định ứng dụng dùng SQLite tại `storage/openshare.sqlite`. Có thể chuyển
sang MySQL bằng `OPENSHARE_DB_DRIVER=mysql` hoặc SQL Server bằng
`OPENSHARE_DB_DRIVER=sqlsrv`. Tham khảo [database/README.md](/home/xuanhuy/btl/database/README.md)
để xem các biến kết nối.

Thiết kế bảng và quan hệ nằm trong [database/README.md](/home/xuanhuy/btl/database/README.md)
và [database/schema.sql](/home/xuanhuy/btl/database/schema.sql).

Mở <http://127.0.0.1:8127>. Tài khoản mẫu:

| Tài khoản | Mật khẩu | Vai trò |
|---|---|---|
| `owner@gmail.com` | `123` | Chủ tệp |
| `recipient@gmail.com` | `123` | Người nhận liên kết |
| `admin@gmail.com` | `123` | Quản trị viên |

Mỗi lần chạy `bash lab.sh reset` sẽ khởi tạo lại dữ liệu mẫu. Không đưa dữ
liệu thật hoặc tệp riêng tư vào thư mục `storage/`.

Dữ liệu mẫu sau khi reset gồm 3 tài khoản, 30 tệp giả và 50 liên kết chia sẻ;
các tệp giả được tạo trong `storage/files/` để có thể kiểm thử luồng tải
xuống ngay trên máy thực hành.

## Chức năng chính

- Đăng nhập, đăng xuất và phân quyền chủ tệp/người nhận/quản trị.
- Đăng ký tài khoản mới bằng email Gmail; tài khoản mới có vai trò người nhận.
- Tạo thư mục, chọn thư mục khi tải tệp và lọc tệp theo thư mục.
- Tải tệp, kiểm tra phần mở rộng, MIME, kích thước và quota.
- Tạo liên kết có hạn dùng, giới hạn lượt tải và mật khẩu tùy chọn.
- Tạo link công khai để gửi cho bất kỳ ai; link có nút sao chép và vẫn chịu
  hạn dùng, giới hạn lượt tải và mật khẩu tùy chọn.
- Khi liên kết chỉ còn một lượt tải, các yêu cầu đồng thời được xếp hàng FIFO;
  yêu cầu đến trước được cấp lượt trước, yêu cầu sau nhận trạng thái chờ.
- Kiểm tra quyền trước khi tải; tệp không nằm dưới `public/`.
- Trước khi tải, hệ thống kiểm tra chủ sở hữu, hạn dùng, mã truy cập, tên tệp
  và đường dẫn vật lý để ngăn truy cập chéo tài khoản hoặc path traversal.
- Thu hồi liên kết, xem lịch sử cấp quyền và xóa tệp hết hạn sau khi xác nhận.
- Dọn tệp hết hạn bằng `bash lab.sh cleanup` để xem trước, rồi dùng
  `bash lab.sh cleanup --delete` để xác nhận xóa.
- Tìm tệp, lọc trạng thái chia sẻ, xuất báo cáo CSV và cảnh báo quota.
- Cảnh báo khi ổ đĩa thiếu chỗ hoặc tệp vật lý không còn tồn tại.
- Khóa tệp khi đọc-cập nhật để hai yêu cầu đồng thời không vượt quá lượt tải.

## Đối chiếu yêu cầu chức năng

| Yêu cầu | Cách sử dụng |
|---|---|
| Đăng nhập và quản lý quota | Đăng nhập tại `/login.php`; quota được kiểm tra khi tải lên và xem tại `/report.php`. |
| Quản lý thư mục và tệp | Tạo thư mục tại `/folders.php`, tải tệp tại `/upload.php`, lọc tệp trên `/`. |
| Kiểm tra tệp trước khi nhận | Giới hạn 10 MB, phần mở rộng cho phép, MIME thực tế, dung lượng đĩa và quota. |
| Liên kết chia sẻ | Chọn tệp → `Chia sẻ`; đặt hạn dùng, số lượt tải và mật khẩu tùy chọn. |
| Kiểm tra quyền trước khi tải | Kiểm tra người nhận, chủ sở hữu, hạn dùng, mật khẩu, lượt tải và đường dẫn vật lý. |
| Thu hồi và lịch sử | Thu hồi tại trang chia sẻ; xem nhật ký tại `/logs.php`. |
| Dọn tệp hết hạn | `bash lab.sh cleanup` để xem trước; `bash lab.sh cleanup --delete` để xóa. |
| Tìm kiếm, lọc và báo cáo | Tìm bằng ô tìm kiếm, lọc `Đang chia sẻ/Riêng tư`, xuất CSV tại `/report.php`. |

## Phép thử bắt buộc

`php tests/run.php` kiểm tra 7 nhóm: đăng nhập, kiểm tra loại tệp, quota,
giới hạn lượt tải đồng thời, hết hạn/thu hồi, chống truy cập chéo chủ sở hữu,
và báo cáo. Các phép thử chỉ dùng lớp nghiệp vụ, không cần máy chủ.
