# Hệ Thống Quản Lý Nhà Trọ

Hệ thống này là một giải pháp toàn diện để quản lý nhà trọ hoặc bất động sản cho thuê, bao gồm quản lý người dùng, chi nhánh, phòng, hợp đồng, thanh toán, dịch vụ, và các tính năng bổ sung như đánh giá, khuyến mãi, sao lưu database, và giới hạn yêu cầu API. Dự án được thiết kế để triển khai dễ dàng trên hosting giá rẻ (PHP 7.4, MySQL 5.6, không cần Composer/Redis), với giao diện cài đặt thân thiện và tài liệu API rõ ràng.

---

## Mục Tiêu

- Cung cấp một hệ thống quản lý nhà trọ linh hoạt, dễ sử dụng, và bảo mật.
- Hỗ trợ khách hàng triển khai với tên thư mục root bất kỳ (ví dụ: `quanlynhatro`, `motel-rental-api`).
- Đảm bảo lỗi và log bằng tiếng Việt, phù hợp với ứng dụng Việt Nam.
- Tối ưu hiệu suất và bảo mật với validate input, middleware xác thực, và rate limiting.

---

## Chức Năng Chính

1. **Quản Lý Người Dùng**:

   - Đăng ký/đăng nhập với vai trò: admin, chủ sở hữu, nhân viên, khách hàng.
   - Quản lý token (JWT), vô hiệu hóa token hết hạn.
   - Gửi thông báo, xử lý yêu cầu hỗ trợ, ghi log hành động.

2. **Quản Lý Chi Nhánh và Phòng**:

   - Tạo và quản lý chi nhánh nhà trọ, liên kết với chủ sở hữu.
   - Định nghĩa loại phòng (phòng đơn, phòng đôi, v.v.) và phòng riêng lẻ.
   - Theo dõi lịch sử giá phòng, trạng thái phòng (trống, đã thuê, bảo trì).

3. **Hợp Đồng và Thanh Toán**:

   - Quản lý hợp đồng thuê giữa khách hàng và phòng.
   - Theo dõi thanh toán (chưa thanh toán, đã thanh toán, quá hạn).
   - Tổng hợp doanh thu chi nhánh hàng tháng.

4. **Dịch Vụ và Tiện Ích**:

   - Quản lý dịch vụ (điện, nước, internet) với giá mặc định theo chi nhánh.
   - Ghi lại mức sử dụng dịch vụ theo phòng và tháng.

5. **Yêu Cầu Bảo Trì**:

   - Khách hàng gửi yêu cầu bảo trì phòng.
   - Nhân viên xử lý và cập nhật trạng thái (đang chờ, đang xử lý, hoàn thành).

6. **Đánh Giá và Khuyến Mãi**:

   - Khách hàng đánh giá chi nhánh/phòng (1-5 sao).
   - Chủ sở hữu tạo khuyến mãi (giảm giá phòng, dịch vụ, hợp đồng).

7. **Tính Năng Bổ Sung**:
   - **Tự động phát hiện base URL**: Cung cấp base URL động cho ReactJS qua `GET /api/v1/config`.
   - **Rate limiting**: Giới hạn 100 request/giờ mỗi IP để bảo vệ API.
   - **Sao lưu database**: Export database thành file SQL qua giao diện admin.
   - **Tài liệu API**: File `api-docs.json` mô tả endpoint, tương thích Postman.

---

## Cấu Trúc Thư Mục

```
[ROOT]/                       // Tên thư mục root do khách hàng đặt
├── api/
│   ├── v1/
│   │   ├── users.php      // API cho người dùng
│   │   ├── rooms.php      // API cho phòng
│   │   ├── contracts.php  // API cho hợp đồng
│   │   └── config.php     // API trả về base URL
├── cache/
│   └── rate_limit.json    // Lưu dữ liệu rate limit
├── config/
│   ├── database.php       // Cấu hình database
│   └── installed.php      // Đánh dấu trạng thái cài đặt
├── core/
│   ├── router.php         // Xử lý định tuyến API
│   ├── database.php       // Kết nối DB
│   ├── auth.php           // Xác thực JWT và middleware
│   ├── validator.php      // Validate input
│   └── helpers.php        // Hàm hỗ trợ
├── dist/
│   └── index.html         // Bundle ReactJS
├── install/
│   ├── index.php          // Trang cài đặt chính
│   ├── step1.php          // Nhập cấu hình database
│   ├── step2.php          // Tạo admin
│   ├── complete.php       // Hoàn tất cài đặt
│   ├── backup.php         // Sao lưu database
│   └── assets/
│       └── styles.css     // CSS giao diện cài đặt
├── migrations/
│   └── schema.sql         // File SQL
├── logs/
│   ├── install.log        // Log lỗi cài đặt
│   └── api.log            // Log lỗi API
├── api-docs.json             // Tài liệu API (Postman)
├── README.md                 // Tài liệu này
├── README-db.md              // Mô tả cơ sở dữ liệu
└── .gitignore                // File bỏ qua Git
└── .htaccess
```

---

## Yêu Cầu Hệ Thống

- **PHP**: 7.4 trở lên.
- **MySQL**: 5.6 trở lên.
- **Web Server**: Apache với mod_rewrite.
- **Ownership**: sudo chown -R www-data:www-data /var/www/html/motel-rental-api/

- **Quyền thư mục**: `config/`, `logs/`, `cache/` cần quyền ghi (chmod 775 hoặc 777).
  sudo chmod -R 775 /var/www/html/motel-rental-api/logs
  sudo chmod -R 775 /var/www/html/motel-rental-api/configs
  sudo chown -R www-data:www-data /var/www/html/motel-rental-api/cache
- **Trình duyệt**: Chrome, Firefox, hoặc bất kỳ trình duyệt hiện đại nào.

---

## Hướng Dẫn Triển Khai

1. **Tải mã nguồn**:

   - Tải thư mục `[ROOT]/` lên `public_html` qua FTP.
   - Đặt tên thư mục root tùy ý (ví dụ: `quanlynhatro`).

2. **Cấu hình quyền**:

   - Chmod 775 hoặc 777 cho `config/`, `logs/`, `cache/`.

3. **Truy cập cài đặt**:

   - Mở `http://domain.com` hoặc `http://localhost/[ROOT]`.
   - Hệ thống chuyển hướng đến `[ROOT]/install/index.php` nếu chưa cài đặt.

4. **Hoàn tất cài đặt**:

   - Nhập thông tin database (host, username, password, database name).
   - Tạo tài khoản admin.
   - Kiểm tra trang chủ ReactJS tại `[ROOT]/dist/index.html`.

5. **Sao lưu database**:

   - Admin truy cập `[ROOT]/install/backup.php` với token JWT để tải file SQL.

6. **ReactJS bundle**:

   - Build dự án ReactJS, copy `build/` vào `[ROOT]/dist/`.
   - Sử dụng `window.APP_CONFIG.baseUrl` hoặc `GET /api/v1/config` để cấu hình API URL.

7. **Test API**:

   - Import `api-docs.json` vào Postman để test các endpoint.
   - Cập nhật `host` trong `api-docs.json` thành domain thực tế.

8. **Xóa thư mục cài đặt**:

   - Xóa `[ROOT]/install/` qua FTP để tăng bảo mật.

9. **Copy thư mục cài đặt**:
   - Trong `[ROOT]/config/`: copy `app.php.example` thành `app.php` và thiết lập các thông số.
   - Trong `[ROOT]/cache/`: copy `rate_limit.json.example` thành `rate_limit.json`

---

## Bảo Mật và Tối Ưu

- **Validate input**: Ngăn SQL injection và XSS bằng PDO và `htmlspecialchars`.
- **Middleware**: Kiểm tra xác thực (JWT) và vai trò (admin, owner, v.v.).
- **Rate limiting**: Giới hạn 100 request/giờ mỗi IP.
- **Sao lưu database**: Chỉ admin truy cập, đảm bảo bảo mật.
- **Tài liệu API**: Hỗ trợ khách hàng tích hợp nhanh chóng.
- **Linh hoạt root**: Không hardcode tên thư mục, tương thích mọi cấu hình.

---

## Hỗ Trợ

- **Tài liệu cơ sở dữ liệu**: Xem `README-db.md` để hiểu cấu trúc database.
- **API**: Import `api-docs.json` vào Postman để test.
- **Liên hệ**: Nếu cần hỗ trợ, gửi yêu cầu qua email hoặc tạo ticket trong hệ thống.
