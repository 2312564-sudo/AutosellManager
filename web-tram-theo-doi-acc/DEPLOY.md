# Deploy lên Vercel

Ứng dụng chạy bằng Docker/FrankenPHP. Database PostgreSQL và ảnh chụp được lưu bên ngoài container để không mất dữ liệu khi Vercel thay instance.

## 1. Tạo dịch vụ lưu trữ

Trong Vercel, mở project → **Storage**:

1. Tạo database PostgreSQL từ Marketplace và kết nối với project.
2. Tạo Blob store với quyền truy cập **Private** và kết nối với project.
3. Bật biến môi trường cho **Production** (và Preview nếu cần). Kiểm tra có `POSTGRES_URL` và `BLOB_READ_WRITE_TOKEN`.

Không commit giá trị của các biến này vào Git hoặc Docker image.

## 2. Chuyển dữ liệu SQLite cũ

Trước khi deploy, sao lưu toàn bộ thư mục `data` của máy chủ cũ, bao gồm `dbname.php`, file `db_*.sqlite` và thư mục `shots`. File SQLite đơn lẻ có thể không chứa đủ dữ liệu nếu máy chủ cũ còn các file journal `-wal` / `-shm`; hãy tạo bản sao nhất quán hoặc dừng ứng dụng trước khi sao chép.

Trên máy có PHP CLI với `pdo_sqlite`, `pdo_pgsql`, `curl` và Vercel CLI:

1. Liên kết thư mục dự án với project Vercel bằng `vercel link`.
2. Chạy công cụ nhập với biến Production của Vercel:

   ```powershell
   vercel env run -e production -- php scripts/migrate-sqlite.php "C:\duong-dan\toi\data"
   ```

Đích PostgreSQL phải chưa có dữ liệu ứng dụng. Công cụ từ chối ghi đè database đã có dữ liệu. Ảnh chụp cũ cũng được tải lên Blob Private. Giữ nguyên bản sao thư mục `data` cũ cho đến khi đăng nhập và kiểm tra deployment thành công.

## 3. Deploy

Đẩy các file dự án lên GitHub và redeploy project Vercel. Nếu project đang ghim framework cũ thay vì Container, chọn preset Container trong Project Settings hoặc chạy:

```powershell
vercel project update --framework container --yes
vercel deploy --prod
```

Sau khi deploy:

- Nếu đã nhập database cũ, đăng nhập bằng tài khoản cũ.
- Nếu chưa có dữ liệu cần nhập, mở `/setup.php` để tạo tài khoản chủ web.
- Không dùng SQLite trong Vercel cho dữ liệu cần giữ lâu dài; thư mục ghi tạm của container được đặt tại `/tmp`.

## Biến môi trường

| Tên | Tạo ở đâu | Bắt buộc |
|---|---|---|
| `POSTGRES_URL` | Kết nối PostgreSQL từ Vercel Marketplace | Có |
| `BLOB_READ_WRITE_TOKEN` | Kết nối Vercel Blob Private | Có để lưu ảnh chụp |
