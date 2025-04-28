<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function handleApiRequest($method, $uri) {
    // Kiểm tra rate limit
    checkRateLimit(getClientIp());

    $endpoint = preg_replace('#^/api/#', '', $uri);
    $endpoint = rtrim($endpoint, '/');
    //error_log("Processed Endpoint: '$endpoint'"); // Log the exact endpoint
    $routes = [
        // Users
        'GET:users' => ['file' => '../api/users.php', 'handler' => 'getUsers', 'middleware' => 'auth:admin'],
        'POST:users' => ['file' => '../api/users.php', 'handler' => 'createUser', 'middleware' => 'auth:admin'],
        'GET:users/([0-9]+)' => ['file' => '../api/users.php', 'handler' => 'getUserById', 'middleware' => 'auth:admin'],
        'PUT:users/([0-9]+)' => ['file' => '../api/users.php', 'handler' => 'updateUser', 'middleware' => 'auth:admin'],
        'PATCH:users/([0-9]+)' => ['file' => '../api/users.php', 'handler' => 'patchUser', 'middleware' => 'auth:admin,owner,employee,customer'],
        'DELETE:users/([0-9]+)' => ['file' => '../api/users.php', 'handler' => 'deleteUser', 'middleware' => 'auth:admin'],
        'POST:register' => ['file' => '../api/users.php', 'handler' => 'registerUser', 'middleware' => 'non-auth'],
        'POST:register/google' => ['file' => '../api/users.php', 'handler' => 'registerGoogleUser', 'middleware' => 'non-auth'],
        // Auth
        'POST:login' => ['file' => '../api/auth.php', 'handler' => 'login', 'middleware' => 'non-auth'],
        'POST:logout' => ['file' => '../api/auth.php', 'handler' => 'logout', 'middleware' => 'auth:admin,owner,employee,customer'],
        // Branches
        'GET:branches' => ['file' => '../api/branches.php', 'handler' => 'getBranches', 'middleware' => 'auth:admin,owner'],
        'POST:branches' => ['file' => '../api/branches.php', 'handler' => 'createBranch', 'middleware' => 'auth:owner'],
        'GET:branches/([0-9]+)' => ['file' => '../api/branches.php', 'handler' => 'getBranchById', 'middleware' => 'auth:admin,owner'],
        'PUT:branches/([0-9]+)' => ['file' => '../api/branches.php', 'handler' => 'updateBranch', 'middleware' => 'auth:owner'],
        'PATCH:branches/([0-9]+)' => ['file' => '../api/branches.php', 'handler' => 'patchBranch', 'middleware' => 'auth:owner'],
        'DELETE:branches/([0-9]+)' => ['file' => '../api/branches.php', 'handler' => 'deleteBranch', 'middleware' => 'auth:owner'],
        // Rooms
        'GET:rooms' => ['file' => '../api/rooms.php', 'handler' => 'getRooms', 'middleware' => null],
        'POST:rooms' => ['file' => '../api/rooms.php', 'handler' => 'createRoom', 'middleware' => 'auth:owner'],
        'GET:rooms/([0-9]+)' => ['file' => '../api/rooms.php', 'handler' => 'getRoomById', 'middleware' => null],
        'PUT:rooms/([0-9]+)' => ['file' => '../api/rooms.php', 'handler' => 'updateRoom', 'middleware' => 'auth:owner'],
        'PATCH:rooms/([0-9]+)' => ['file' => '../api/rooms.php', 'handler' => 'patchRoom', 'middleware' => 'auth:owner'],
        'DELETE:rooms/([0-9]+)' => ['file' => '../api/rooms.php', 'handler' => 'deleteRoom', 'middleware' => 'auth:owner'],
        // Room Types
        'GET:room_types' => ['file' => '../api/room_types.php', 'handler' => 'getRoomTypes', 'middleware' => null],
        'POST:room_types' => ['file' => '../api/room_types.php', 'handler' => 'createRoomType', 'middleware' => 'auth:admin,owner'],
        'GET:room_types/([0-9]+)' => ['file' => '../api/room_types.php', 'handler' => 'getRoomTypeById', 'middleware' => null],
        'PUT:room_types/([0-9]+)' => ['file' => '../api/room_types.php', 'handler' => 'updateRoomType', 'middleware' => 'auth:admin,owner'],
        'PATCH:room_types/([0-9]+)' => ['file' => '../api/room_types.php', 'handler' => 'patchRoomType', 'middleware' => 'auth:admin,owner'],
        'DELETE:room_types/([0-9]+)' => ['file' => '../api/room_types.php', 'handler' => 'deleteRoomType', 'middleware' => 'auth:admin,owner'],
        // Employees
        'POST:employees' => ['file' => '../api/employees.php', 'handler' => 'createEmployee', 'middleware' => 'auth:admin,owner'],
        'PUT:employees/([0-9]+)' => ['file' => '../api/employees.php', 'handler' => 'updateEmployee', 'middleware' => 'auth:admin,owner'],
        'PATCH:employees/([0-9]+)' => ['file' => '../api/employees.php', 'handler' => 'patchEmployee', 'middleware' => 'auth:admin,owner'],
        'DELETE:employees/([0-9]+)' => ['file' => '../api/employees.php', 'handler' => 'deleteEmployee', 'middleware' => 'auth:admin,owner'],
        // Utility Usage
        'GET:utility_usage' => ['file' => '../api/utility_usage.php', 'handler' => 'getUtilityUsage', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:utility_usage' => ['file' => '../api/utility_usage.php', 'handler' => 'createUtilityUsage', 'middleware' => 'auth:admin,owner,employee'],
        'GET:utility_usage/([0-9]+)' => ['file' => '../api/utility_usage.php', 'handler' => 'getUtilityUsageById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:utility_usage/([0-9]+)' => ['file' => '../api/utility_usage.php', 'handler' => 'updateUtilityUsage', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:utility_usage/([0-9]+)' => ['file' => '../api/utility_usage.php', 'handler' => 'patchUtilityUsage', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:utility_usage/([0-9]+)' => ['file' => '../api/utility_usage.php', 'handler' => 'deleteUtilityUsage', 'middleware' => 'auth:admin,owner'],
        // Maintenance Requests
        'GET:maintenance_requests' => ['file' => '../api/maintenance_requests.php', 'handler' => 'getMaintenanceRequests', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:maintenance_requests' => ['file' => '../api/maintenance_requests.php', 'handler' => 'createMaintenanceRequest', 'middleware' => 'auth:admin,owner,employee,customer'],
        'GET:maintenance_requests/([0-9]+)' => ['file' => '../api/maintenance_requests.php', 'handler' => 'getMaintenanceRequestById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:maintenance_requests/([0-9]+)' => ['file' => '../api/maintenance_requests.php', 'handler' => 'updateMaintenanceRequest', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:maintenance_requests/([0-9]+)' => ['file' => '../api/maintenance_requests.php', 'handler' => 'patchMaintenanceRequest', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:maintenance_requests/([0-9]+)' => ['file' => '../api/maintenance_requests.php', 'handler' => 'deleteMaintenanceRequest', 'middleware' => 'auth:admin,owner'],
        // Reviews
        'GET:reviews' => ['file' => '../api/reviews.php', 'handler' => 'getReviews', 'middleware' => null],
        'POST:reviews' => ['file' => '../api/reviews.php', 'handler' => 'createReview', 'middleware' => 'auth:customer'],
        'GET:reviews/([0-9]+)' => ['file' => '../api/reviews.php', 'handler' => 'getReviewById', 'middleware' => null],
        'PUT:reviews/([0-9]+)' => ['file' => '../api/reviews.php', 'handler' => 'updateReview', 'middleware' => 'auth:customer'],
        'PATCH:reviews/([0-9]+)' => ['file' => '../api/reviews.php', 'handler' => 'patchReview', 'middleware' => 'auth:customer'],
        'DELETE:reviews/([0-9]+)' => ['file' => '../api/reviews.php', 'handler' => 'deleteReview', 'middleware' => 'auth:admin,owner,customer'],
        // Promotions
        'GET:promotions' => ['file' => '../api/promotions.php', 'handler' => 'getPromotions', 'middleware' => null],
        'POST:promotions' => ['file' => '../api/promotions.php', 'handler' => 'createPromotion', 'middleware' => 'auth:admin,owner'],
        'GET:promotions/([0-9]+)' => ['file' => '../api/promotions.php', 'handler' => 'getPromotionById', 'middleware' => null],
        'PUT:promotions/([0-9]+)' => ['file' => '../api/promotions.php', 'handler' => 'updatePromotion', 'middleware' => 'auth:admin,owner'],
        'PATCH:promotions/([0-9]+)' => ['file' => '../api/promotions.php', 'handler' => 'patchPromotion', 'middleware' => 'auth:admin,owner'],
        'DELETE:promotions/([0-9]+)' => ['file' => '../api/promotions.php', 'handler' => 'deletePromotion', 'middleware' => 'auth:admin,owner'],
        // Notifications
        'GET:notifications' => ['file' => '../api/notifications.php', 'handler' => 'getNotifications', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:notifications' => ['file' => '../api/notifications.php', 'handler' => 'createNotification', 'middleware' => 'auth:admin,owner'],
        'GET:notifications/([0-9]+)' => ['file' => '../api/notifications.php', 'handler' => 'getNotificationById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PATCH:notifications/([0-9]+)' => ['file' => '../api/notifications.php', 'handler' => 'patchNotification', 'middleware' => 'auth:admin,owner,employee,customer'],
        'DELETE:notifications/([0-9]+)' => ['file' => '../api/notifications.php', 'handler' => 'deleteNotification', 'middleware' => 'auth:admin,owner'],
        // Tickets
        'GET:tickets' => ['file' => '../api/tickets.php', 'handler' => 'getTickets', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:tickets' => ['file' => '../api/tickets.php', 'handler' => 'createTicket', 'middleware' => 'auth:admin,owner,employee,customer'],
        'GET:tickets/([0-9]+)' => ['file' => '../api/tickets.php', 'handler' => 'getTicketById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:tickets/([0-9]+)' => ['file' => '../api/tickets.php', 'handler' => 'updateTicket', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:tickets/([0-9]+)' => ['file' => '../api/tickets.php', 'handler' => 'patchTicket', 'middleware' => 'auth:admin,owner,employee,customer'],
        'DELETE:tickets/([0-9]+)' => ['file' => '../api/tickets.php', 'handler' => 'deleteTicket', 'middleware' => 'auth:admin,owner'],
        // Contracts
        'GET:contracts' => ['file' => '../api/contracts.php', 'handler' => 'getContracts', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:contracts' => ['file' => '../api/contracts.php', 'handler' => 'createContract', 'middleware' => 'auth:admin,owner,employee'],
        'GET:contracts/([0-9]+)' => ['file' => '../api/contracts.php', 'handler' => 'getContractById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:contracts/([0-9]+)' => ['file' => '../api/contracts.php', 'handler' => 'updateContract', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:contracts/([0-9]+)' => ['file' => '../api/contracts.php', 'handler' => 'patchContract', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:contracts/([0-9]+)' => ['file' => '../api/contracts.php', 'handler' => 'deleteContract', 'middleware' => 'auth:admin,owner'],
        // Payments
        'GET:payments' => ['file' => '../api/payments.php', 'handler' => 'getPayments', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:payments' => ['file' => '../api/payments.php', 'handler' => 'createPayment', 'middleware' => 'auth:admin,owner,employee,customer'],
        'GET:payments/([0-9]+)' => ['file' => '../api/payments.php', 'handler' => 'getPaymentById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:payments/([0-9]+)' => ['file' => '../api/payments.php', 'handler' => 'updatePayment', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:payments/([0-9]+)' => ['file' => '../api/payments.php', 'handler' => 'patchPayment', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:payments/([0-9]+)' => ['file' => '../api/payments.php', 'handler' => 'deletePayment', 'middleware' => 'auth:admin,owner'],
        // Invoices
        'GET:invoices' => ['file' => '../api/invoices.php', 'handler' => 'getInvoices', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:invoices' => ['file' => '../api/invoices.php', 'handler' => 'createInvoice', 'middleware' => 'auth:admin,owner,employee'],
        'GET:invoices/([0-9]+)' => ['file' => '../api/invoices.php', 'handler' => 'getInvoiceById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:invoices/([0-9]+)' => ['file' => '../api/invoices.php', 'handler' => 'updateInvoice', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:invoices/([0-9]+)' => ['file' => '../api/invoices.php', 'handler' => 'patchInvoice', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:invoices/([0-9]+)' => ['file' => '../api/invoices.php', 'handler' => 'deleteInvoice', 'middleware' => 'auth:admin,owner'],
        // Revenue Statistics
        'GET:revenue_statistics' => ['file' => '../api/revenue_statistics.php', 'handler' => 'getRevenueStatistics', 'middleware' => 'auth:admin,owner'],
        // Services
        'GET:services' => ['file' => '../api/services.php', 'handler' => 'getServices', 'middleware' => null],
        'POST:services' => ['file' => '../api/services.php', 'handler' => 'createService', 'middleware' => 'auth:admin'],
        'GET:services/([0-9]+)' => ['file' => '../api/services.php', 'handler' => 'getServiceById', 'middleware' => null],
        'PUT:services/([0-9]+)' => ['file' => '../api/services.php', 'handler' => 'updateService', 'middleware' => 'auth:admin'],
        'PATCH:services/([0-9]+)' => ['file' => '../api/services.php', 'handler' => 'patchService', 'middleware' => 'auth:admin'],
        'DELETE:services/([0-9]+)' => ['file' => '../api/services.php', 'handler' => 'deleteService', 'middleware' => 'auth:admin'],
        // Branch Service Defaults
        'GET:branch_service_defaults' => ['file' => '../api/branch_service_defaults.php', 'handler' => 'getBranchServiceDefaults', 'middleware' => 'auth:admin,owner'],
        'POST:branch_service_defaults' => ['file' => '../api/branch_service_defaults.php', 'handler' => 'createBranchServiceDefault', 'middleware' => 'auth:admin,owner'],
        'GET:branch_service_defaults/([0-9]+)' => ['file' => '../api/branch_service_defaults.php', 'handler' => 'getBranchServiceDefaultById', 'middleware' => 'auth:admin,owner'],
        'PUT:branch_service_defaults/([0-9]+)' => ['file' => '../api/branch_service_defaults.php', 'handler' => 'updateBranchServiceDefault', 'middleware' => 'auth:admin,owner'],
        'PATCH:branch_service_defaults/([0-9]+)' => ['file' => '../api/branch_service_defaults.php', 'handler' => 'patchBranchServiceDefault', 'middleware' => 'auth:admin,owner'],
        'DELETE:branch_service_defaults/([0-9]+)' => ['file' => '../api/branch_service_defaults.php', 'handler' => 'deleteBranchServiceDefault', 'middleware' => 'auth:admin,owner'],
        // Settings
        'GET:settings' => ['file' => '../api/settings.php', 'handler' => 'getSettings', 'middleware' => 'auth:admin'],
        'POST:settings' => ['file' => '../api/settings.php', 'handler' => 'createSetting', 'middleware' => 'auth:admin'],
        'GET:settings/([0-9]+)' => ['file' => '../api/settings.php', 'handler' => 'getSettingById', 'middleware' => 'auth:admin'],
        'PUT:settings/([0-9]+)' => ['file' => '../api/settings.php', 'handler' => 'updateSetting', 'middleware' => 'auth:admin'],
        'PATCH:settings/([0-9]+)' => ['file' => '../api/settings.php', 'handler' => 'patchSetting', 'middleware' => 'auth:admin'],
        'DELETE:settings/([0-9]+)' => ['file' => '../api/settings.php', 'handler' => 'deleteSetting', 'middleware' => 'auth:admin'],
        // Room Occupants
        'GET:room_occupants' => ['file' => '../api/room_occupants.php', 'handler' => 'getRoomOccupants', 'middleware' => 'auth:admin,owner,employee,customer'],
        'POST:room_occupants' => ['file' => '../api/room_occupants.php', 'handler' => 'createRoomOccupant', 'middleware' => 'auth:admin,owner,employee'],
        'GET:room_occupants/([0-9]+)' => ['file' => '../api/room_occupants.php', 'handler' => 'getRoomOccupantById', 'middleware' => 'auth:admin,owner,employee,customer'],
        'PUT:room_occupants/([0-9]+)' => ['file' => '../api/room_occupants.php', 'handler' => 'updateRoomOccupant', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:room_occupants/([0-9]+)' => ['file' => '../api/room_occupants.php', 'handler' => 'patchRoomOccupant', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:room_occupants/([0-9]+)' => ['file' => '../api/room_occupants.php', 'handler' => 'deleteRoomOccupant', 'middleware' => 'auth:admin,owner,employee'],
        // Employee Assignments
        'GET:employee_assignments' => ['file' => '../api/employee_assignments.php', 'handler' => 'getEmployeeAssignments', 'middleware' => 'auth:admin,owner'],
        'POST:employee_assignments' => ['file' => '../api/employee_assignments.php', 'handler' => 'createEmployeeAssignment', 'middleware' => 'auth:admin,owner'],
        'GET:employee_assignments/([0-9]+)' => ['file' => '../api/employee_assignments.php', 'handler' => 'getEmployeeAssignmentById', 'middleware' => 'auth:admin,owner'],
        'PUT:employee_assignments/([0-9]+)' => ['file' => '../api/employee_assignments.php', 'handler' => 'updateEmployeeAssignment', 'middleware' => 'auth:admin,owner'],
        'PATCH:employee_assignments/([0-9]+)' => ['file' => '../api/employee_assignments.php', 'handler' => 'patchEmployeeAssignment', 'middleware' => 'auth:admin,owner'],
        'DELETE:employee_assignments/([0-9]+)' => ['file' => '../api/employee_assignments.php', 'handler' => 'deleteEmployeeAssignment', 'middleware' => 'auth:admin,owner'],
        // Branch Customers
        'GET:branch_customers' => ['file' => '../api/branch_customers.php', 'handler' => 'getBranchCustomers', 'middleware' => 'auth:admin,owner,employee'],
        'POST:branch_customers' => ['file' => '../api/branch_customers.php', 'handler' => 'createBranchCustomer', 'middleware' => 'auth:admin,owner,employee'],
        'GET:branch_customers/([0-9]+)' => ['file' => '../api/branch_customers.php', 'handler' => 'getBranchCustomerById', 'middleware' => 'auth:admin,owner,employee'],
        'PUT:branch_customers/([0-9]+)' => ['file' => '../api/branch_customers.php', 'handler' => 'updateBranchCustomer', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:branch_customers/([0-9]+)' => ['file' => '../api/branch_customers.php', 'handler' => 'patchBranchCustomer', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:branch_customers/([0-9]+)' => ['file' => '../api/branch_customers.php', 'handler' => 'deleteBranchCustomer', 'middleware' => 'auth:admin,owner,employee'],
        // Room Price History
        'GET:room_price_history' => ['file' => '../api/room_price_history.php', 'handler' => 'getRoomPriceHistory', 'middleware' => 'auth:admin,owner,employee'],
        'POST:room_price_history' => ['file' => '../api/room_price_history.php', 'handler' => 'createRoomPriceHistory', 'middleware' => 'auth:admin,owner'],
        'GET:room_price_history/([0-9]+)' => ['file' => '../api/room_price_history.php', 'handler' => 'getRoomPriceHistoryById', 'middleware' => 'auth:admin,owner,employee'],
        'PUT:room_price_history/([0-9]+)' => ['file' => '../api/room_price_history.php', 'handler' => 'updateRoomPriceHistory', 'middleware' => 'auth:admin,owner'],
        'PATCH:room_price_history/([0-9]+)' => ['file' => '../api/room_price_history.php', 'handler' => 'patchRoomPriceHistory', 'middleware' => 'auth:admin,owner'],
        'DELETE:room_price_history/([0-9]+)' => ['file' => '../api/room_price_history.php', 'handler' => 'deleteRoomPriceHistory', 'middleware' => 'auth:admin,owner'],
        // Room Status History
        'GET:room_status_history' => ['file' => '../api/room_status_history.php', 'handler' => 'getRoomStatusHistory', 'middleware' => 'auth:admin,owner,employee'],
        'POST:room_status_history' => ['file' => '../api/room_status_history.php', 'handler' => 'createRoomStatusHistory', 'middleware' => 'auth:admin,owner,employee'],
        'GET:room_status_history/([0-9]+)' => ['file' => '../api/room_status_history.php', 'handler' => 'getRoomStatusHistoryById', 'middleware' => 'auth:admin,owner,employee'],
        'PUT:room_status_history/([0-9]+)' => ['file' => '../api/room_status_history.php', 'handler' => 'updateRoomStatusHistory', 'middleware' => 'auth:admin,owner,employee'],
        'PATCH:room_status_history/([0-9]+)' => ['file' => '../api/room_status_history.php', 'handler' => 'patchRoomStatusHistory', 'middleware' => 'auth:admin,owner,employee'],
        'DELETE:room_status_history/([0-9]+)' => ['file' => '../api/room_status_history.php', 'handler' => 'deleteRoomStatusHistory', 'middleware' => 'auth:admin,owner'],
        // Config
        'GET:config' => ['file' => '../api/utils/config.php', 'handler' => 'getConfig', 'middleware' => null],
    ];

    foreach ($routes as $route => $config) {
        list($routeMethod, $routePattern) = explode(':', $route);
        $pattern = '#^' . $routePattern . '$#';
        //error_log("Checking pattern: $pattern against endpoint: $endpoint"); // Log pattern and endpoint
        if ($method === $routeMethod && preg_match($pattern, $endpoint)) {
            error_log("Matched route: $route");
            try {
                if ($config['middleware'] === 'non-auth') {
                    nonAuthMiddleware();
                } elseif ($config['middleware'] && strpos($config['middleware'], 'auth') === 0) {
                    $roles = explode(':', $config['middleware'])[1];
                    authMiddleware($roles);
                }
                $absolutePath = realpath(__DIR__ . '/' . $config['file']);
                error_log("Loading file: $absolutePath");
                if ($absolutePath === false) {
                    responseJson(['status' => 'error', 'message' => 'Không tìm thấy file handler'], 500);
                    logError("Không tìm thấy file: " . __DIR__ . '/' . $config['file']);
                    return;
                }
                require_once $absolutePath;

                if (function_exists($config['handler'])) {
                    call_user_func($config['handler']);
                } else {
                    responseJson(['status' => 'error', 'message' => 'Handler not found'], 500);
                }
            } catch (Exception $e) {
                logError($e->getMessage());
                responseJson(['status' => 'error', 'message' => 'Internal server error'], 500);
            }
            return;
        } else {
            //error_log("No match for route: $routeMethod:$routePattern"); // Log non-matching routes
        }
    }

    responseJson(['status' => 'error', 'message' => 'Endpoint not found'], 404);
}


?>