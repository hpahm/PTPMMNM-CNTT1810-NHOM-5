# Thiết kế cơ sở dữ liệu OpenShare

OpenShare mặc định sử dụng SQLite tại `storage/openshare.sqlite`. Tệp
`database/schema.sql` là schema SQLite; ứng dụng cũng tự tạo schema khi khởi
động. Có thể chuyển sang SQL Server 2019 bằng `OPENSHARE_DB_DRIVER=sqlsrv`.
Schema SQLite cũ vẫn nằm ở `database/schema.sql` để tham khảo, nhưng không còn
là driver mặc định.

Có thể chạy bằng MySQL 8.0.16+ theo cách tương tự schema của `lab5` bằng
`OPENSHARE_DB_DRIVER=mysql`. Schema tương ứng nằm ở
`database/schema-mysql.sql`; ứng dụng sẽ tự tạo bảng và dữ liệu mẫu khi khởi
động lần đầu.

## Quan hệ chính

- `users` lưu tài khoản, vai trò, mật khẩu đã băm và quota.
- `folders` thuộc một người dùng; `files` thuộc chủ tệp và một thư mục.
- `shares` là liên kết cấp quyền tải cho một người nhận.
- `share_queue` lưu thứ tự các yêu cầu khi liên kết chỉ còn lượt tải cuối.
- `access_logs` lưu các lần tải, từ chối, chia sẻ, thu hồi và thao tác quản trị.

Khóa ngoại bảo đảm tệp và liên kết không tồn tại độc lập. Xóa người dùng sẽ
xóa thư mục, tệp và liên kết của họ theo quy tắc `ON DELETE CASCADE`; tệp vật
lý vẫn được quản lý riêng trong `storage/files/`.

## Khởi tạo dữ liệu

Trong SSMS, trước tiên tạo database:

```sql
CREATE DATABASE OpenShare;
GO
```

Sau đó chạy ứng dụng. Lần khởi động đầu tiên sẽ tự tạo các bảng và dữ liệu mẫu.

Ứng dụng tạo 3 tài khoản, 30 tệp giả, 50 liên kết và 30 tệp vật lý mẫu.
PHP cần bật `pdo_sqlsrv` cùng ODBC Driver for SQL Server. Với Windows
Authentication, PHP nên chạy trên Windows cùng tài khoản đã đăng nhập; nếu
chạy trong WSL cần cấu hình Kerberos/ODBC riêng.

Nếu chưa cài được driver SQL Server, chạy kiểm thử cục bộ bằng SQLite:

```bash
export OPENSHARE_DB_DRIVER=sqlite
bash lab.sh reset
bash lab.sh test
```

Với MySQL, tạo database và cấu hình kết nối trước khi chạy:

```sql
CREATE DATABASE openshare
  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
```

```bash
export OPENSHARE_DB_DRIVER=mysql
export OPENSHARE_DB_HOST=127.0.0.1
export OPENSHARE_DB_PORT=3306
export OPENSHARE_DB_NAME=openshare
export OPENSHARE_DB_USER=openshare
export OPENSHARE_DB_PASSWORD='mat-khau'
bash lab.sh reset
```
