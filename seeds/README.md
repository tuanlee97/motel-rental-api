```sql
-- File: seed.sql
-- Mô tả: Seed dữ liệu mẫu cho hệ thống quản lý nhà trọ/khách sạn
-- password : 123456
-- Seed dữ liệu cho bảng users
INSERT INTO users (username, name, email, password, phone, role, status, provider, created_at) VALUES
('admin01', 'Nguyễn Văn An', 'admin01@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0912345678', 'admin', 'active', 'email', NOW()),
('owner01', 'Trần Thị Bình', 'owner01@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0987654321', 'owner', 'active', 'email', NOW()),
('employee01', 'Lê Văn Cường', 'employee01@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0933445566', 'employee', 'active', 'email', NOW()),
('customer01', 'Phạm Thị Dung', 'customer01@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234567', 'customer', 'active', 'email', NOW()),
('customer02', 'Hoàng Văn Em', 'customer02@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0977889900', 'customer', 'active', 'email', NOW());

-- Seed dữ liệu cho bảng branches
INSERT INTO branches (owner_id, name, address, phone, revenue, created_at) VALUES
(2, 'Nhà trọ Bình Minh', '123 Đường Láng, Đống Đa, Hà Nội', '0987654321', 15000000.00, NOW()),
(2, 'Nhà trọ Sao Mai', '456 Đường Nguyễn Trãi, Thanh Xuân, Hà Nội', '0987654322', 12000000.00, NOW());

-- Seed dữ liệu cho bảng room_types
INSERT INTO room_types (name, description, default_price, created_at) VALUES
('Phòng đơn', 'Phòng 15m2, có điều hòa, WC riêng', 2500000.00, NOW()),
('Phòng đôi', 'Phòng 25m2, có điều hòa, WC riêng, ban công', 3500000.00, NOW()),
('Phòng VIP', 'Phòng 35m2, đầy đủ tiện nghi, view đẹp', 5000000.00, NOW());

-- Seed dữ liệu cho bảng rooms
INSERT INTO rooms (branch_id, type_id, name, price, status, created_at) VALUES
(1, 1, 'Phòng 101', 2500000.00, 'available', NOW()),
(1, 2, 'Phòng 201', 3500000.00, 'occupied', NOW()),
(1, 3, 'Phòng 301', 5000000.00, 'maintenance', NOW()),
(2, 1, 'Phòng A1', 2600000.00, 'available', NOW()),
(2, 2, 'Phòng B1', 3600000.00, 'occupied', NOW());

-- Seed dữ liệu cho bảng contracts
INSERT INTO contracts (room_id, user_id, start_date, end_date, status, created_at, created_by, branch_id, deposit) VALUES
(2, 4, '2025-01-01', '2025-12-31', 'active', NOW(), 2, 1, 3500000.00),
(5, 5, '2025-02-01', '2025-08-01', 'active', NOW(), 2, 2, 3600000.00);

-- Seed dữ liệu cho bảng payments
INSERT INTO payments (contract_id, amount, due_date, payment_date, status, created_at) VALUES
(1, 3500000.00, '2025-02-01', NULL, 'pending', NOW()),
(1, 3500000.00, '2025-03-01', '2025-02-28', 'paid', NOW()),
(2, 3600000.00, '2025-03-01', NULL, 'pending', NOW());

-- Seed dữ liệu cho bảng services
INSERT INTO services (name, price, unit, created_at) VALUES
('Điện', 3500.00, 'kWh', NOW()),
('Nước', 20000.00, 'm3', NOW()),
('Internet', 100000.00, 'tháng', NOW());

-- Seed dữ liệu cho bảng branch_service_defaults
INSERT INTO branch_service_defaults (branch_id, service_id, default_price, created_at) VALUES
(1, 1, 3500.00, NOW()),
(1, 2, 20000.00, NOW()),
(1, 3, 100000.00, NOW()),
(2, 1, 3600.00, NOW()),
(2, 2, 21000.00, NOW()),
(2, 3, 110000.00, NOW());

-- Seed dữ liệu cho bảng utility_usage
INSERT INTO utility_usage (room_id, service_id, month, usage_amount, custom_price, recorded_at) VALUES
(2, 1, '2025-01', 100.00, NULL, NOW()),
(2, 2, '2025-01', 5.00, NULL, NOW()),
(5, 1, '2025-02', 120.00, NULL, NOW()),
(5, 2, '2025-02', 6.00, NULL, NOW());

-- Seed dữ liệu cho bảng maintenance_requests
INSERT INTO maintenance_requests (room_id, description, status, created_at) VALUES
(3, 'Sửa điều hòa bị hỏng', 'pending', NOW()),
(5, 'Thay bóng đèn phòng tắm', 'in_progress', NOW());

-- Seed dữ liệu cho bảng logs
INSERT INTO logs (user_id, action, ip_address, affected_table, affected_record_id, created_at) VALUES
(1, 'Tạo hợp đồng mới', '192.168.1.1', 'contracts', 1, NOW()),
(2, 'Cập nhật trạng thái phòng', '192.168.1.2', 'rooms', 3, NOW());

-- Seed dữ liệu cho bảng room_occupants
INSERT INTO room_occupants (room_id, user_id, relation, created_at) VALUES
(2, 4, 'Chủ hợp đồng', NOW()),
(5, 5, 'Chủ hợp đồng', NOW());

-- Seed dữ liệu cho bảng tickets
INSERT INTO tickets (user_id, subject, message, status, created_at) VALUES
(4, 'Hỏi về tiền điện', 'Tiền điện tháng này cao bất thường', 'open', NOW()),
(5, 'Yêu cầu sửa chữa', 'Cần sửa ống nước phòng B1', 'pending', NOW());

-- Seed dữ liệu cho bảng notifications
INSERT INTO notifications (user_id, message, is_read, created_at) VALUES
(4, 'Hợp đồng của bạn đã được tạo thành công', FALSE, NOW()),
(5, 'Thanh toán tháng 3 đã đến hạn', FALSE, NOW());

-- Seed dữ liệu cho bảng settings
INSERT INTO settings (key_name, value, type, created_at) VALUES
('max_rooms_per_branch', '50', 'int', NOW()),
('default_currency', 'VND', 'string', NOW());



-- Seed dữ liệu cho bảng employee_assignments
INSERT INTO employee_assignments (employee_id, branch_id, created_at) VALUES
(3, 1, NOW()),
(3, 2, NOW());

-- Seed dữ liệu cho bảng revenue_statistics
INSERT INTO revenue_statistics (branch_id, year, month, revenue, created_at) VALUES
(1, 2025, 1, 7000000.00, NOW()),
(2, 2025, 1, 3600000.00, NOW());

-- Seed dữ liệu cho bảng branch_customers
INSERT INTO branch_customers (branch_id, user_id, created_at) VALUES
(1, 4, NOW()),
(2, 5, NOW());

-- Seed dữ liệu cho bảng room_price_history
INSERT INTO room_price_history (room_id, price, effective_date, created_at) VALUES
(2, 3500000.00, '2025-01-01', NOW()),
(5, 3600000.00, '2025-02-01', NOW());

-- Seed dữ liệu cho bảng room_status_history
INSERT INTO room_status_history (room_id, status, change_date, reason, created_at) VALUES
(3, 'maintenance', NOW(), 'Điều hòa hỏng', NOW()),
(5, 'occupied', NOW(), 'Khách thuê mới', NOW());

-- Seed dữ liệu cho bảng reviews
INSERT INTO reviews (user_id, branch_id, room_id, rating, comment, created_at) VALUES
(4, 1, 2, 4, 'Phòng sạch sẽ, dịch vụ tốt', NOW()),
(5, 2, 5, 3, 'Phòng ổn nhưng wifi yếu', NOW());

-- Seed dữ liệu cho bảng promotions
INSERT INTO promotions (branch_id, name, discount_percentage, start_date, end_date, applicable_to, created_at) VALUES
(1, 'Khuyến mãi Tết', 10.00, '2025-01-01', '2025-02-01', 'room', NOW()),
(2, 'Giảm giá mùa hè', 15.00, '2025-06-01', '2025-08-31', 'contract', NOW());

-- Seed dữ liệu cho bảng invoices
INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at) VALUES
(1, 1, 3500000.00, '2025-02-01', 'pending', NOW()),
(2, 2, 3600000.00, '2025-03-01', 'pending', NOW());
```

### Giải thích về dữ liệu seed:
1. **users**: Tạo 5 người dùng với các vai trò khác nhau (admin, owner, employee, customer) với thông tin thực tế như tên, email, số điện thoại Việt Nam.
2. **branches**: Tạo 2 nhà trọ thuộc sở hữu của user `owner01`, đặt tại Hà Nội với doanh thu mẫu.
3. **room_types**: Tạo 3 loại phòng với giá và mô tả phù hợp (phòng đơn, phòng đôi, phòng VIP).
4. **rooms**: Tạo 5 phòng thuộc 2 nhà trọ, với trạng thái khác nhau (available, occupied, maintenance).
5. **contracts**: Tạo 2 hợp đồng thuê phòng cho 2 khách hàng, thuộc 2 nhà trọ.
6. **payments**: Tạo các khoản thanh toán liên quan đến hợp đồng, bao gồm trạng thái pending và paid.
7. **services**: Tạo các dịch vụ cơ bản (điện, nước, internet) với đơn vị và giá phù hợp.
8. **branch_service_defaults**: Gán giá dịch vụ cho từng nhà trọ, có sự khác biệt nhỏ giữa các nhà trọ.
9. **utility_usage**: Ghi nhận mức sử dụng điện, nước cho các phòng đã thuê.
10. **maintenance_requests**: Tạo yêu cầu bảo trì cho phòng bị hỏng.
11. **logs**: Ghi lại hành động của người dùng như tạo hợp đồng, cập nhật trạng thái phòng.
12. **room_occupants**: Gán khách hàng làm người ở chính trong phòng.
13. **tickets**: Tạo vé hỗ trợ từ khách hàng về vấn đề tiền điện và sửa chữa.
14. **notifications**: Gửi thông báo về hợp đồng và thanh toán đến khách hàng.
15. **settings**: Thiết lập cấu hình hệ thống như số phòng tối đa và đơn vị tiền tệ.
16. **token_blacklist**: Thêm token mẫu vào danh sách đen.
17. **employee_assignments**: Gán nhân viên cho cả 2 nhà trọ.
18. **revenue_statistics**: Ghi nhận doanh thu tháng 1/2025 cho 2 nhà trọ.
19. **branch_customers**: Liên kết khách hàng với nhà trọ.
20. **room_price_history**: Lưu lịch sử giá phòng.
21. **room_status_history**: Lưu lịch sử trạng thái phòng.
22. **reviews**: Khách hàng để lại đánh giá cho phòng và nhà trọ.
23. **promotions**: Tạo chương trình khuyến mãi cho Tết và mùa hè.
24. **invoices**: Tạo hóa đơn cho các hợp đồng.