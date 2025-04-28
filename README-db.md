# Mô Tả Cơ Sở Dữ Liệu - Hệ Thống Quản Lý Nhà Trọ

Tài liệu này mô tả cấu trúc cơ sở dữ liệu của hệ thống quản lý nhà trọ, bao gồm danh sách bảng, mối quan hệ, chỉ mục, luồng dữ liệu, và các chiến lược tối ưu hóa. Cơ sở dữ liệu sử dụng MySQL (ENGINE=InnoDB, mã hóa UTF8MB4) với 23 bảng, hỗ trợ quản lý người dùng, chi nhánh, phòng, hợp đồng, thanh toán, dịch vụ, và các tính năng bổ sung.

---

## Mục Đích

Cơ sở dữ liệu được thiết kế để:
- Quản lý thông tin người dùng (admin, chủ sở hữu, nhân viên, khách hàng).
- Theo dõi chi nhánh, phòng, hợp đồng, thanh toán, và dịch vụ (điện, nước, v.v.).
- Hỗ trợ yêu cầu bảo trì, đánh giá, khuyến mãi, thông báo, và sao lưu database.
- Đảm bảo tính toàn vẹn dữ liệu, hiệu suất truy vấn, và bảo mật.

---

## Danh Sách Bảng

Cơ sở dữ liệu bao gồm **23 bảng**, được chia thành các nhóm chức năng:

### Quản Lý Người Dùng
1. **`users`**: Lưu thông tin người dùng (email, mật khẩu, vai trò, trạng thái).
2. **`token_blacklist`**: Quản lý token xác thực bị vô hiệu hóa.
3. **`notifications`**: Lưu thông báo gửi đến người dùng.
4. **`tickets`**: Quản lý yêu cầu hỗ trợ từ người dùng.
5. **`logs`**: Ghi lại hành động của người dùng để kiểm tra.

### Quản Lý Chi Nhánh và Tài Sản
6. **`branches`**: Đại diện cho các chi nhánh nhà trọ, liên kết với chủ sở hữu.
7. **`room_types`**: Xác định loại phòng (phòng đơn, phòng đôi, v.v.).
8. **`rooms`**: Lưu thông tin phòng riêng lẻ, liên kết với chi nhánh và loại phòng.
9. **`room_price_history`**: Theo dõi lịch sử thay đổi giá phòng.
10. **`room_status_history`**: Ghi lại lịch sử thay đổi trạng thái phòng.
11. **`maintenance_requests`**: Quản lý yêu cầu bảo trì phòng.

### Quản Lý Hợp Đồng và Thanh Toán
12. **`contracts`**: Lưu thông tin hợp đồng thuê giữa khách hàng và phòng.
13. **`payments`**: Theo dõi các khoản thanh toán liên quan đến hợp đồng.
14. **`revenue_statistics`**: Tổng hợp doanh thu hàng tháng của chi nhánh.

### Quản Lý Dịch Vụ và Tiện Ích
15. **`services`**: Lưu thông tin dịch vụ (điện, nước, internet, v.v.).
16. **`branch_service_defaults`**: Xác định giá dịch vụ mặc định theo chi nhánh.
17. **`utility_usage`**: Ghi lại mức sử dụng dịch vụ theo phòng và tháng.

### Quản Lý Mối Quan Hệ
18. **`employee_assignments`**: Gán nhân viên cho chi nhánh.
19. **`branch_customers`**: Liên kết khách hàng với chi nhánh.
20. **`room_occupants`**: Theo dõi người ở trong phòng ngoài người ký hợp đồng.

### Tính Năng Bổ Sung
21. **`settings`**: Lưu cấu hình hệ thống (key-value).
22. **`reviews`**: Lưu đánh giá của người dùng về chi nhánh hoặc phòng.
23. **`promotions`**: Quản lý chương trình khuyến mãi (giảm giá phòng, dịch vụ, hợp đồng).
24. **`token_blacklist`**: Chặn token còn hạn nhưng đã thực hiện logout không được phép dùng lại để đăng nhập.
---

## Mối Quan Hệ Giữa Các Bảng

Các bảng được liên kết thông qua **khóa ngoại** với ràng buộc `ON DELETE CASCADE` để đảm bảo tính toàn vẹn dữ liệu. Dưới đây là các mối quan hệ chính:

### Người Dùng (`users`)
- **`branches.owner_id` → `users.id`**: Chi nhánh thuộc về chủ sở hữu.
- **`contracts.user_id` → `users.id`**: Hợp đồng liên kết với khách hàng.
- **`contracts.created_by` → `users.id`**: Người tạo hợp đồng.
- **`employee_assignments.employee_id` → `users.id`**: Nhân viên được gán.
- **`branch_customers.user_id` → `users.id`**: Khách hàng liên kết với chi nhánh.
- **`room_occupants.user_id` → `users.id`**: Người ở thêm trong phòng.
- **`tickets.user_id` → `users.id`**: Yêu cầu hỗ trợ của người dùng.
- **`notifications.user_id` → `users.id`**: Thông báo gửi đến người dùng.
- **`token_blacklist.user_id` → `users.id`**: Token bị vô hiệu hóa của người dùng.
- **`logs.user_id` → `users.id`**: Nhật ký hành động của người dùng.
- **`reviews.user_id` → `users.id`**: Đánh giá của người dùng.

### Chi Nhánh (`branches`)
- **`rooms.branch_id` → `branches.id`**: Phòng thuộc chi nhánh.
- **`contracts.branch_id` → `branches.id`**: Hợp đồng liên kết với chi nhánh.
- **`branch_service_defaults.branch_id` → `branches.id`**: Giá dịch vụ theo chi nhánh.
- **`employee_assignments.branch_id` → `branches.id`**: Nhân viên gán cho chi nhánh.
- **`branch_customers.branch_id` → `branches.id`**: Khách hàng liên kết với chi nhánh.
- **`revenue_statistics.branch_id` → `branches.id`**: Doanh thu theo chi nhánh.
- **`reviews.branch_id` → `branches.id`**: Đánh giá về chi nhánh.
- **`promotions.branch_id` → `branches.id`**: Khuyến mãi theo chi nhánh.

### Phòng (`rooms`)
- **`rooms.type_id` → `room_types.id`**: Phòng thuộc loại phòng.
- **`contracts.room_id` → `rooms.id`**: Hợp đồng cho phòng.
- **`room_occupants.room_id` → `rooms.id`**: Người ở trong phòng.
- **`maintenance_requests.room_id` → `rooms.id`**: Yêu cầu bảo trì cho phòng.
- **`utility_usage.room_id` → `rooms.id`**: Sử dụng dịch vụ theo phòng.
- **`room_price_history.room_id` → `rooms.id`**: Lịch sử giá phòng.
- **`room_status_history.room_id` → `rooms.id`**: Lịch sử trạng thái phòng.
- **`reviews.room_id` → `rooms.id`**: Đánh giá về phòng.

### Hợp Đồng (`contracts`)
- **`payments.contract_id` → `contracts.id`**: Thanh toán liên kết với hợp đồng.

### Dịch Vụ (`services`)
- **`branch_service_defaults.service_id` → `services.id`**: Giá dịch vụ theo chi nhánh.
- **`utility_usage.service_id` → `services.id`**: Sử dụng dịch vụ theo phòng.

---

## Chỉ Mục

- **Khóa chính**: Mỗi bảng có cột `id` làm `PRIMARY KEY`, tự động lập chỉ mục.
- **Khóa duy nhất**: Đảm bảo tính duy nhất (ví dụ: `users.email`, `utility_usage.unique_room_service_month`).
- **Chỉ mục kết hợp**: Tối ưu hóa truy vấn phổ biến (ví dụ: `rooms.idx_rooms_branch_status`, `payments.idx_payments_contract_date`).
- **Chỉ mục đơn**: Hỗ trợ tra cứu nhanh (ví dụ: `users.idx_users_email`, `branches.idx_branches_name`).

---

## Luồng Dữ Liệu Chính

Dưới đây là các luồng dữ liệu mô tả cách hệ thống xử lý các hoạt động quan trọng:

### Đăng Ký và Phân Quyền Người Dùng
1. Người dùng đăng ký qua `users` (vai trò: admin, owner, employee, customer).
2. Token xác thực (JWT) được tạo, token hết hạn lưu vào `token_blacklist`.
3. Thông báo chào mừng lưu trong `notifications`.

### Quản Lý Chi Nhánh và Phòng
1. Chủ sở hữu tạo chi nhánh trong `branches`.
2. Loại phòng định nghĩa trong `room_types`.
3. Phòng tạo trong `rooms`, liên kết với chi nhánh và loại phòng.
4. Giá phòng cập nhật trong `room_price_history`.
5. Trạng thái phòng ghi lại trong `room_status_history`.

### Hợp Đồng và Thanh Toán
1. Khách hàng ký hợp đồng, lưu trong `contracts`.
2. Thanh toán tạo trong `payments`, liên kết với hợp đồng.
3. Doanh thu chi nhánh tổng hợp trong `revenue_statistics`.

### Quản Lý Dịch Vụ
1. Dịch vụ định nghĩa trong `services`.
2. Giá dịch vụ mặc định lưu trong `branch_service_defaults`.
3. Mức sử dụng dịch vụ ghi trong `utility_usage`.

### Yêu Cầu Bảo Trì
1. Khách hàng gửi yêu cầu bảo trì, lưu trong `maintenance_requests`.
2. Nhân viên xử lý, cập nhật trạng thái.

### Đánh Giá và Khuyến Mãi
1. Khách hàng gửi đánh giá trong `reviews`.
2. Chủ sở hữu tạo khuyến mãi trong `promotions`.

### Thông Báo và Hỗ Trợ
1. Thông báo gửi qua `notifications`.
2. Yêu cầu hỗ trợ lưu trong `tickets`.

### Sao Lưu và Kiểm Tra
1. Admin export database qua `[ROOT]/install/backup.php`.
2. Hành động người dùng ghi trong `logs`.
3. Cấu hình hệ thống lưu trong `settings`.

---

## Tối Ưu Hóa

- **Toàn vẹn dữ liệu**: Sử dụng `FOREIGN KEY` với `ON DELETE CASCADE`.
- **Hiệu suất**: Chỉ mục tối ưu cho truy vấn phổ biến, loại bỏ chỉ mục trùng lặp.
- **Kiểu dữ liệu**: Nhất quán (ví dụ: `revenue_statistics` dùng `year` và `month` kiểu INT).
- **Bảo mật**: Validate input, mã hóa mật khẩu, ngăn SQL injection/XSS.

---

## Công Nghệ

- **Cơ sở dữ liệu**: MySQL (ENGINE=InnoDB, mã hóa UTF8MB4).
- **Migration**: File `migrations/schema.sql`, thực thi qua PHP PDO.
- **Ràng buộc**: Khóa ngoại, chỉ mục, và ràng buộc kiểu dữ liệu (ENUM, CHECK).

---

## Hướng Dẫn Sử Dụng

1. **Khởi tạo database**:
   - Tạo database MySQL (ví dụ: `quanlynhatro`).
   - Import `migrations/schema.sql` qua phpMyAdmin hoặc chạy trong quá trình cài đặt.

2. **Sao lưu**:
   - Admin truy cập `[ROOT]/install/backup.php` để tải file SQL.

3. **Kiểm tra schema**:
   - Xem `migrations/schema.sql` để hiểu cấu trúc bảng và chỉ mục.