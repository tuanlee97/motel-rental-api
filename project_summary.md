# Tóm Tắt Dự Án: Hệ Thống Quản Lý Nhà Trọ

## Mô Tả
Hệ thống quản lý nhà trọ là một giải pháp web-based để quản lý người dùng, chi nhánh, phòng, hợp đồng, thanh toán, dịch vụ, và các tính năng bổ sung như bảo trì, đánh giá, khuyến mãi. Sử dụng PHP 7.4, MySQL 5.6, Apache, không cần Composer/Redis, dễ triển khai trên hosting giá rẻ. Giao diện cài đặt thân thiện, API RESTful với tài liệu Postman.

## Mục Tiêu
- Quản lý toàn diện nhà trọ: người dùng (admin, owner, employee, customer), chi nhánh, phòng, hợp đồng, thanh toán, dịch vụ.
- Tối ưu bảo mật: validate input, JWT auth, rate limiting (100 req/h/IP).
- Linh hoạt: hỗ trợ tên thư mục root tùy ý, tự động phát hiện base URL.
- Hỗ trợ sao lưu database và log lỗi bằng tiếng Việt.

## Công Nghệ
- **Backend**: PHP 7.4, MySQL (InnoDB, UTF8MB4).
- **Frontend**: ReactJS (bundle trong `dist/`).
- **Web Server**: Apache (mod_rewrite).
- **Auth**: JWT, Google OAuth (đăng ký).
- **API Docs**: `api-docs.json` (Postman).
- **Migration**: `migrations/schema.sql` (PDO).

## Cấu Trúc Database
- **23 bảng** (MySQL, InnoDB, UTF8MB4):
  - **Người dùng**: `users`, `token_blacklist`, `notifications`, `tickets`, `logs`.
  - **Chi nhánh/phòng**: `branches`, `room_types`, `rooms`, `room_price_history`, `room_status_history`, `maintenance_requests`.
  - **Hợp đồng/thanh toán**: `contracts`, `payments`, `revenue_statistics`.
  - **Dịch vụ**: `services`, `branch_service_defaults`, `utility_usage`.
  - **Mối quan hệ**: `employee_assignments`, `branch_customers`, `room_occupants`.
  - **Bổ sung**: `settings`, `reviews`, `promotions`.
- **Ràng buộc**: FOREIGN KEY với ON DELETE CASCADE.
- **Chỉ mục**: PRIMARY KEY, UNIQUE, INDEX cho truy vấn nhanh.
- **Luồng dữ liệu**:
  - Đăng ký: Tạo user, thông báo chào mừng (`notifications`).
  - Chi nhánh/phòng: Owner tạo chi nhánh, phòng, theo dõi giá/trạng thái.
  - Hợp đồng: Khách hàng ký hợp đồng, thanh toán, thống kê doanh thu.
  - Dịch vụ: Ghi mức sử dụng, tính phí theo chi nhánh.
  - Bảo trì: Yêu cầu từ khách, nhân viên xử lý.
  - Đánh giá/khuyến mãi: Khách đánh giá, owner tạo khuyến mãi.

## Cấu Trúc Thư Mục
```
[ROOT]/ (tên tùy ý, ví dụ: quanlynhatro)
├── api/v1/
│   ├── users.php (CRUD người dùng, đăng ký)
│   ├── rooms.php (CRUD phòng)
│   ├── contracts.php (CRUD hợp đồng)
│   └── config.php (trả base URL)
├── cache/
│   └── rate_limit.json (lưu rate limit)
├── config/
│   ├── database.php (cấu hình DB)
│   └── installed.php (trạng thái cài đặt)
├── core/
│   ├── router.php (định tuyến API)
│   ├── database.php (kết nối PDO)
│   ├── auth.php (JWT, Google OAuth)
│   ├── validator.php (validate input)
│   └── helpers.php (hàm hỗ trợ)
├── dist/
│   └── index.html (ReactJS bundle)
├── install/
│   ├── index.php (trang cài đặt)
│   ├── step1.php (cấu hình DB)
│   ├── step2.php (tạo admin)
│   ├── complete.php (hoàn tất)
│   ├── backup.php (sao lưu DB)
│   └── assets/styles.css
├── migrations/
│   └── schema.sql (cấu trúc DB)
├── logs/
│   ├── install.log (lỗi cài đặt)
│   └── api.log (lỗi API)
├── public/
│   ├── index.php (entry point)
│   └── .htaccess (Apache)
├── api-docs.json (tài liệu API)
├── README.md (hướng dẫn chung)
├── README-db.md (mô tả DB)
├── .gitignore
└── .htaccess
```

## Danh Sách File Code Chính
1. **core/database.php**: Kết nối PDO singleton, đọc cấu hình từ `config/database.php`.
2. **core/helpers.php**: Hàm hỗ trợ (`getBasePath`, `responseJson`, `logError`, `sanitizeInput`, `checkRateLimit`).
3. **core/router.php**: Định tuyến API, áp dụng middleware (auth, non-auth), xử lý rate limit.
4. **core/auth.php**: Xử lý JWT (`generateJWT`, `verifyJWT`, `authMiddleware`, `nonAuthMiddleware`, `verifyGoogleToken`).
5. **api/v1/users.php**: API người dùng (getUsers, createUser, registerUser, registerGoogleUser).
6. **config/database.php**: Cấu hình DB (host, username, password, database).
7. **migrations/schema.sql**: Định nghĩa 23 bảng, chỉ mục, khóa ngoại.
8. **api-docs.json**: Tài liệu API (Postman).
9. **logs/api.log**: Log lỗi API.
10. **cache/rate_limit.json**: Lưu trạng thái rate limit.

## Luồng Dữ Liệu Chính
- **Khởi tạo**: Router nhận yêu cầu, kiểm tra rate limit (`cache/rate_limit.json`), áp dụng middleware.
- **Xác thực**:
  - JWT: `auth.php` kiểm tra token, trả user_id, role.
  - Google OAuth: Xác minh token, lấy email/username.
- **API**:
  - Input validated (`sanitizeInput`, PDO).
  - Truy vấn DB qua PDO (`database.php`).
  - Output JSON (`responseJson`).
- **Đăng ký**:
  - `POST /api/v1/register`: Validate email/username/password, tạo user, thông báo, trả JWT.
  - `POST /api/v1/register/google`: Xác minh Google token, tạo/đăng nhập user, trả JWT.
- **Log**: Lỗi ghi vào `logs/api.log`.
- **Sao lưu**: Admin export DB qua `install/backup.php`.

## API Hiện Có
- **GET /api/v1/users**: Lấy danh sách user (auth:admin).
- **POST /api/v1/users**: Tạo user (auth:admin).
- **POST /api/v1/register**: Đăng ký email (non-auth).
- **POST /api/v1/register/google**: Đăng ký Google (non-auth).
- **GET /api/v1/rooms**: Lấy danh sách phòng (public).
- **GET /api/v1/config**: Trả base URL (public).

## Yêu Cầu Hệ Thống
- PHP 7.4+, MySQL 5.6+, Apache (mod_rewrite).
- Quyền ghi: `config/`, `logs/`, `cache/` (chmod 775/777).
- Trình duyệt: Chrome, Firefox.

## Hướng Dẫn Triển Khai
1. Tải mã nguồn vào `public_html/[ROOT]`.
2. Chmod 775/777 cho `config/`, `logs/`, `cache/`.
3. Truy cập `http://domain/[ROOT]/install`, nhập DB info, tạo admin.
4. Import `migrations/schema.sql`.
5. Build ReactJS, copy vào `dist/`.
6. Test API với `api-docs.json` (Postman).
7. Xóa `install/` sau khi hoàn tất.

## Bảo Mật
- Validate input: PDO, `sanitizeInput` ngăn SQL injection/XSS.
- JWT: Xác thực endpoint, middleware kiểm tra role.
- Rate limit: 100 req/h/IP.
- Mật khẩu: Mã hóa bằng `password_hash`.
- Google OAuth: Xác minh token qua Google API.

## Trạng Thái Hiện Tại
- Core hoàn thiện: router, auth, database, helpers.
- API users: CRUD, đăng ký (email, Google).
- Database: 23 bảng, sẵn sàng cho các tính năng tiếp theo.
- Todo: API đăng nhập, quản lý phòng, hợp đồng, v.v.

## Hỗ Trợ
- Xem `README.md`, `README-db.md`.
- Test API với `api-docs.json`.
- Log lỗi: `logs/api.log`.
- Liên hệ qua ticket trong hệ thống.