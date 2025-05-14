-- Xóa dữ liệu cũ (nếu có)
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM branch_customers;
DELETE FROM employee_assignments;
DELETE FROM room_occupants;
DELETE FROM tickets;
DELETE FROM notifications;
DELETE FROM maintenance_requests;
DELETE FROM utility_usage;
DELETE FROM services;
DELETE FROM invoices;
DELETE FROM payments;
DELETE FROM contracts;
DELETE FROM rooms;
DELETE FROM room_types;
DELETE FROM branches;
DELETE FROM users;

SET FOREIGN_KEY_CHECKS = 1;

-- Thêm dữ liệu vào bảng users --password: 123456
INSERT INTO users 
(username, name, email, password, phone, role, status, provider, created_by) 
VALUES 
-- Quản trị viên hệ thống
('admin', 'Admin', 'admin@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234567', 'admin', 'active', 'email', NULL),

-- Chủ nhà trọ
('tranthibich', 'Trần Thị Bích', 'owner1@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234568', 'owner', 'active', 'email', 1),
('lehongchau', 'Lê Hồng Châu', 'owner2@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234569', 'owner', 'active', 'email', 1),

-- Nhân viên
('phamthid', 'Phạm Thị Dung', 'employee1@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234570', 'employee', 'active', 'email', 2),
('doanvanh', 'Đoàn Văn Hậu', 'employee2@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234571', 'employee', 'active', 'email', 2),
('nguyenvank', 'Nguyễn Văn Khánh', 'employee3@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234572', 'employee', 'active', 'email', 3),
('tranthil', 'Trần Thị Lan', 'employee4@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234573', 'employee', 'active', 'email', 3),

-- Khách hàng
('luongminhm', 'Lương Minh Mẫn', 'customer1@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234574', 'customer', 'active', 'email', 4),
('nguyenngocn', 'Nguyễn Ngọc Nhi', 'customer2@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234575', 'customer', 'active', 'email', 4),
('phamhoango', 'Phạm Hoàng Oanh', 'customer3@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234576', 'customer', 'active', 'email', 5),
('tranquangp', 'Trần Quang Phúc', 'customer4@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234577', 'customer', 'active', 'email', 6),
('lehungq', 'Lê Hùng Quân', 'customer5@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234578', 'customer', 'active', 'email', 6),
('ngothir', 'Ngô Thị Rinh', 'customer6@example.com', '$2y$10$BqySEFjlKULrzDOuZDxsO.ATUeLikBfozPm5zbUfl93MVBQ6EWdhu', '0901234579', 'customer', 'active', 'email', 7);

-- Thêm dữ liệu vào bảng branches
INSERT INTO branches (owner_id, name, address, phone) VALUES
(2, 'Nhà Trọ Bình An', '123 Đường Láng, Quận Đống Đa, Hà Nội', '0901234580'),
(3, 'Nhà Trọ Hạnh Phúc', '456 Đường Nguyễn Trãi, Quận 5, TP.HCM', '0901234581');

-- Thêm dữ liệu vào bảng room_types
INSERT INTO room_types (branch_id, name, description) VALUES
(1, 'Phòng Đơn', 'Phòng 20m2, 1 giường đơn'),
(1, 'Phòng Đôi', 'Phòng 30m2, 2 giường đơn'),
(2, 'Phòng Tiêu Chuẩn', 'Phòng 25m2, đầy đủ tiện nghi'),
(2, 'Phòng Cao Cấp', 'Phòng 35m2, có ban công');

-- Thêm dữ liệu vào bảng rooms
INSERT INTO rooms (branch_id, type_id, name, price, status) VALUES
(1, 1, 'Phòng 101', 2000000, 'occupied'),
(1, 2, 'Phòng 201', 3500000, 'available'),
(1, 1, 'Phòng 102', 2000000, 'maintenance'),
(2, 3, 'Phòng A1', 2500000, 'occupied'),
(2, 4, 'Phòng B1', 4000000, 'available');

-- Thêm dữ liệu vào bảng contracts
INSERT INTO contracts (room_id, user_id, start_date, end_date, status, created_by, branch_id, deposit) VALUES
(1, 8, '2025-01-01', '2025-12-31', 'active', 4, 1, 2000000),
(4, 11, '2025-02-01', '2025-12-31', 'active', 6, 2, 2500000);

-- Thêm dữ liệu vào bảng payments
INSERT INTO payments (contract_id, amount, due_date, payment_date, status) VALUES
(1, 2000000, '2025-02-01', '2025-01-31', 'paid'),
(1, 2000000, '2025-03-01', NULL, 'pending'),
(2, 2500000, '2025-03-01', NULL, 'pending');

-- Thêm dữ liệu vào bảng invoices
INSERT INTO invoices (contract_id, branch_id, amount, due_date, status) VALUES
(1, 1, 2200000, '2025-02-01', 'paid'),
(1, 1, 2200000, '2025-03-01', 'pending'),
(2, 2, 2700000, '2025-03-01', 'pending');

-- Thêm dữ liệu vào bảng services
INSERT INTO services (branch_id, name, price, unit, type) VALUES
(1, 'Điện', 3500, 'kWh', 'electricity'),
(1, 'Nước', 20000, 'm3', 'water'),
(2, 'Điện', 4000, 'kWh', 'electricity'),
(2, 'Nước', 22000, 'm3', 'water'),
(2, 'Internet', 150000, 'tháng', 'other');

-- Thêm dữ liệu vào bảng utility_usage
INSERT INTO utility_usage (room_id, service_id, month, usage_amount, old_reading, new_reading,custom_price, recorded_at) VALUES
(1, 1, '2025-01', 100, 0, 100,NULL, '2025-01-31 10:00:00'),
(1, 2, '2025-01', 5, 0, 5,NULL, '2025-01-31 10:00:00'),
(4, 3, '2025-02', 120, 0, 120, NULL, '2025-02-28 10:00:00'),
(4, 4, '2025-02', 6, 0, 6 ,NULL, '2025-02-28 10:00:00');

-- Thêm dữ liệu vào bảng maintenance_requests
INSERT INTO maintenance_requests (room_id, description, status, created_at, created_by) VALUES
(1, 'Sửa ống nước bị rò rỉ', 'pending', '2025-02-01 09:00:00', 8),
(3, 'Kiểm tra hệ thống điện', 'in_progress', '2025-02-02 10:00:00', 4),
(4, 'Sửa cửa sổ bị kẹt', 'completed', '2025-02-03 11:00:00', 11);

-- Thêm dữ liệu vào bảng notifications
INSERT INTO notifications (user_id, message, is_read, created_at) VALUES
(8, 'Hóa đơn tháng 2 đã được tạo.', FALSE, '2025-02-01 08:00:00'),
(11, 'Yêu cầu bảo trì của bạn đã được xử lý.', TRUE, '2025-02-03 12:00:00'),
(2, 'Khách hàng mới đã được thêm vào chi nhánh.', FALSE, '2025-02-01 09:00:00');

-- Thêm dữ liệu vào bảng tickets
INSERT INTO tickets (user_id, subject, message, status, created_at) VALUES
(8, 'Hỏi về hóa đơn', 'Hóa đơn tháng 2 có sai sót, vui lòng kiểm tra.', 'open', '2025-02-02 10:00:00'),
(11, 'Yêu cầu hỗ trợ', 'Cần hỗ trợ về hợp đồng thuê.', 'pending', '2025-02-03 11:00:00');

-- Thêm dữ liệu vào bảng room_occupants
INSERT INTO room_occupants (room_id, user_id, relation, created_at) VALUES
(1, 8, 'Chủ hợp đồng', '2025-01-01 09:00:00'),
(1, 9, 'Người thân', '2025-01-01 09:00:00'),
(4, 11, 'Chủ hợp đồng', '2025-02-01 09:00:00'),
(4, 12, 'Bạn cùng phòng', '2025-02-01 09:00:00');

-- Thêm dữ liệu vào bảng employee_assignments
INSERT INTO employee_assignments (employee_id, branch_id, created_at, created_by) VALUES
(4, 1, '2025-01-01 08:00:00', 2),
(5, 1, '2025-01-01 08:00:00', 2),
(6, 2, '2025-01-01 08:00:00', 3),
(7, 2, '2025-01-01 08:00:00', 3);

-- Thêm dữ liệu vào bảng branch_customers
INSERT INTO branch_customers (branch_id, user_id, created_at, created_by) VALUES
(1, 8, '2025-01-01 08:00:00', 4),
(1, 9, '2025-01-01 08:00:00', 4),
(1, 10, '2025-01-01 08:00:00', 5),
(2, 11, '2025-02-01 08:00:00', 6),
(2, 12, '2025-02-01 08:00:00', 6),
(2, 13, '2025-02-01 08:00:00', 7);