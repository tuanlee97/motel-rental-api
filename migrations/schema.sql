-- Bảng users: Lưu thông tin người dùng
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255),
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(15),
    role ENUM('admin', 'owner', 'employee', 'customer') DEFAULT 'customer',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive',
    provider ENUM('email', 'google') DEFAULT 'email',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_users_email (email),
    INDEX idx_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng branches: Lưu thông tin chi nhánh (nhà trọ)
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(15),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_branches_owner_id (owner_id),
    INDEX idx_branches_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng room_types: Lưu loại phòng, liên kết với chi nhánh
CREATE TABLE IF NOT EXISTS room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    default_price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_room_types_branch_id (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng rooms: Lưu thông tin phòng
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    type_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id) REFERENCES room_types(id) ON DELETE CASCADE,
    INDEX idx_rooms_branch_status (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng contracts: Lưu hợp đồng thuê phòng
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    branch_id INT NOT NULL,
    deposit DECIMAL(10,2) DEFAULT 0.00,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_contracts_room_status (room_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng payments: Lưu thông tin thanh toán
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    payment_date DATE,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    INDEX idx_payments_contract_date (contract_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng invoices: Lưu hóa đơn
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    branch_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_invoices_contract_due_date (contract_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng services: Lưu dịch vụ, liên kết với chi nhánh
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_services_branch_id (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng utility_usage: Lưu thông tin sử dụng dịch vụ
CREATE TABLE IF NOT EXISTS utility_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    service_id INT NOT NULL,
    month VARCHAR(7) NOT NULL,
    usage_amount DECIMAL(10,2) NOT NULL,
    custom_price DECIMAL(10,2) NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    UNIQUE KEY unique_room_service_month (room_id, service_id, month),
    INDEX idx_utility_usage_room_month (room_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng maintenance_requests: Lưu yêu cầu bảo trì
CREATE TABLE IF NOT EXISTS maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_maintenance_requests_room_status (room_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng notifications: Lưu thông báo
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng tickets: Lưu yêu cầu hỗ trợ
CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('open', 'closed', 'pending') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tickets_status_date (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng room_occupants: Lưu thông tin người ở trong phòng
CREATE TABLE IF NOT EXISTS room_occupants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    relation VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_room_occupants_room_id (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng employee_assignments: Lưu phân công nhân viên cho chi nhánh
CREATE TABLE IF NOT EXISTS employee_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    branch_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (employee_id, branch_id),
    INDEX idx_employee_assignments_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng branch_customers: Lưu thông tin khách hàng của chi nhánh
CREATE TABLE IF NOT EXISTS branch_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_branch_user (branch_id, user_id),
    INDEX idx_branch_customers_branch_id (branch_id),
    INDEX idx_branch_customers_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng token_blacklist: Lưu trữ các token JWT bị thu hồi
CREATE TABLE IF NOT EXISTS token_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_blacklist_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;